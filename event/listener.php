<?php
/**
 * Pangram AI Post Checker — event listener
 *
 * Fires after every new post is saved. For first posts:
 *   - fraction_ai <= approve_threshold → approve post + activate user
 *   - fraction_ai >= ban_threshold     → ban user (by user_id + email) + admin log
 *   - between thresholds               → leave in queue for manual review
 */
namespace claude\pangram\event;

use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface as db_driver;
use phpbb\log\log_interface;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    protected $config;
    protected $db;
    protected $content_visibility;
    protected $log;
    protected $user;
    protected $phpbb_root_path;
    protected $php_ext;

    const PANGRAM_API = 'https://text.external-api.pangram.com';

    public function __construct(
        config $config,
        db_driver $db,
        content_visibility $content_visibility,
        log_interface $log,
        user $user,
        $phpbb_root_path,
        $php_ext
    )
    {
        $this->config             = $config;
        $this->db                 = $db;
        $this->content_visibility = $content_visibility;
        $this->log                = $log;
        $this->user               = $user;
        $this->phpbb_root_path    = $phpbb_root_path;
        $this->php_ext            = $php_ext;
    }

    public static function getSubscribedEvents()
    {
        return array(
            'core.user_setup'      => 'load_language',
            'core.submit_post_end' => 'check_post_for_ai',
        );
    }

    // -----------------------------------------------------------------------
    // Load ACP language early so sidebar category names are translated
    // -----------------------------------------------------------------------

    public function load_language($event)
    {
        // Load on every page so LOG_PANGRAM_BAN resolves in both ACP and MCP log views
        $this->user->add_lang_ext('claude/pangram', 'acp/pangram');
    }

    // -----------------------------------------------------------------------
    // Main hook
    // -----------------------------------------------------------------------

    public function check_post_for_ai($event)
    {
        if (empty($this->config['pangram_enabled']))
        {
            return;
        }

        $api_key = isset($this->config['pangram_api_key']) ? trim($this->config['pangram_api_key']) : '';
        if (empty($api_key))
        {
            return;
        }

        $mode = $event['mode'];
        // Only new posts / replies / quotes — not edits, deletes, etc.
        if (!in_array($mode, array('post', 'reply', 'quote'), true))
        {
            return;
        }

        $data    = $event['data'];
        $post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
        if (!$post_id)
        {
            return;
        }

        // Fetch the actual post row so we have reliable visibility + IDs
        $sql = 'SELECT post_visibility, poster_id, topic_id, forum_id, post_text
            FROM ' . POSTS_TABLE . '
            WHERE post_id = ' . $post_id;
        $result = $this->db->sql_query($sql);
        $post   = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$post || (int) $post['post_visibility'] === ITEM_DELETED)
        {
            return;  // not found or already deleted
        }

        $post_visibility = (int) $post['post_visibility'];

        $poster_id = (int) $post['poster_id'];
        $topic_id  = (int) $post['topic_id'];
        $forum_id  = (int) $post['forum_id'];

        if ($poster_id == ANONYMOUS)
        {
            return;
        }

        // Only first posts: user has never had a post approved
        $sql = 'SELECT user_posts, user_type, user_email, username
            FROM ' . USERS_TABLE . '
            WHERE user_id = ' . $poster_id;
        $result   = $this->db->sql_query($sql);
        $user_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$user_row || (int) $user_row['user_posts'] > 0)
        {
            return;
        }

        // Prefer the message from $data (pre-storage); fall back to DB post_text
        $raw_message = '';
        if (!empty($data['message']))
        {
            $raw_message = (string) $data['message'];
        }
        else if (!empty($post['post_text']))
        {
            $raw_message = (string) $post['post_text'];
        }

        $clean_text = $this->clean_post_text($raw_message);

        if (strlen($clean_text) < 50)
        {
            return;  // too short for reliable detection
        }

        // Call Pangram
        $fraction_ai = $this->get_pangram_score($clean_text, $api_key);

        if ($fraction_ai === null)
        {
            return;  // API error → leave in queue
        }

        $ai_pct            = (int) round($fraction_ai * 100);
        $approve_threshold = (int) $this->config['pangram_approve_threshold'];
        $ban_threshold     = (int) $this->config['pangram_ban_threshold'];

        if ($ai_pct <= $approve_threshold)
        {
            if ($post_visibility === ITEM_UNAPPROVED)
            {
                $this->approve_post_and_user($post_id, $topic_id, $forum_id, $poster_id, $mode, $user_row);
            }
            // else: post already live (normal user), nothing to do
        }
        else if ($ai_pct >= $ban_threshold)
        {
            // Pull post back into queue if it went live immediately (admin-created / no-queue users)
            if ($post_visibility === ITEM_APPROVED)
            {
                $this->content_visibility->set_post_visibility(
                    ITEM_UNAPPROVED,
                    $post_id,
                    $topic_id,
                    $forum_id,
                    $poster_id,
                    time(),
                    '',
                    ($mode === 'post'),
                    true
                );
            }
            $this->ban_user($poster_id, $user_row, $post_id, $topic_id, $ai_pct);
        }
        // else: middle ground → manual review
    }

    // -----------------------------------------------------------------------
    // Pangram API
    // -----------------------------------------------------------------------

    protected function get_pangram_score($text, $api_key)
    {
        if (!function_exists('curl_init'))
        {
            return null;
        }

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
        );

        // Submit task
        $ch = curl_init(self::PANGRAM_API . '/task');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(array('text' => $text, 'model' => 'pangram-4')),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = curl_exec($ch);
        curl_close($ch);

        if (empty($body))
        {
            return null;
        }

        $submit = json_decode($body, true);
        if (empty($submit['task_id']))
        {
            return null;
        }

        $task_id = $submit['task_id'];

        // Poll until STAGE_SUCCESS (max 10 × 1.5 s = 15 s)
        for ($i = 0; $i < 10; $i++)
        {
            usleep(1500000);

            $ch = curl_init(self::PANGRAM_API . '/task/' . rawurlencode($task_id));
            curl_setopt_array($ch, array(
                CURLOPT_HTTPHEADER     => array('x-api-key: ' . $api_key),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ));
            $body = curl_exec($ch);
            curl_close($ch);

            if (empty($body))
            {
                continue;
            }

            $result = json_decode($body, true);

            if (empty($result['stage']))
            {
                continue;
            }

            if ($result['stage'] === 'STAGE_SUCCESS')
            {
                return isset($result['fraction_ai']) ? (float) $result['fraction_ai'] : null;
            }

            if ($result['stage'] === 'STAGE_FAILED')
            {
                return null;
            }
        }

        return null;  // timeout
    }

    // -----------------------------------------------------------------------
    // Approve
    // -----------------------------------------------------------------------

    protected function approve_post_and_user($post_id, $topic_id, $forum_id, $poster_id, $mode, $user_row)
    {
        $is_starter = ($mode === 'post');  // true if this is a new topic

        // content_visibility handles post + topic visibility + forum/topic stat sync
        $this->content_visibility->set_post_visibility(
            ITEM_APPROVED,
            $post_id,
            $topic_id,
            $forum_id,
            $poster_id,
            time(),
            '',
            $is_starter,
            true  // is_latest
        );

        // Increment approved post count for the user
        $sql = 'UPDATE ' . USERS_TABLE . '
            SET user_posts = user_posts + 1
            WHERE user_id = ' . $poster_id;
        $this->db->sql_query($sql);

        // Activate the user if they're still in the "inactive new member" state
        if ((int) $user_row['user_type'] === USER_INACTIVE)
        {
            $sql = 'UPDATE ' . USERS_TABLE . '
                SET user_type = ' . USER_NORMAL . ',
                    user_inactive_reason = 0,
                    user_inactive_time = 0
                WHERE user_id = ' . $poster_id;
            $this->db->sql_query($sql);
        }
    }

    // -----------------------------------------------------------------------
    // Ban
    // -----------------------------------------------------------------------

    protected function ban_user($poster_id, $user_row, $post_id, $topic_id, $ai_pct)
    {
        $admin_reason = 'AI-generated content detected by Pangram (score: ' . $ai_pct . '%)';
        $user_reason  = 'Your account has been banned for posting AI-generated content.';

        // Avoid double-banning
        $sql = 'SELECT ban_id FROM ' . BANLIST_TABLE . '
            WHERE ban_userid = ' . $poster_id;
        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$exists)
        {
            // Ban by user_id (permanent)
            $sql = 'INSERT INTO ' . BANLIST_TABLE . ' ' . $this->db->sql_build_array('INSERT', array(
                'ban_userid'      => $poster_id,
                'ban_ip'          => '',
                'ban_email'       => '',
                'ban_start'       => time(),
                'ban_end'         => 0,
                'ban_exclude'     => 0,
                'ban_reason'      => $admin_reason,
                'ban_give_reason' => $user_reason,
            ));
            $this->db->sql_query($sql);

            // Also ban the email address to block re-registration
            $email = strtolower(trim((string) $user_row['user_email']));
            if ($email !== '')
            {
                $sql = 'INSERT INTO ' . BANLIST_TABLE . ' ' . $this->db->sql_build_array('INSERT', array(
                    'ban_userid'      => 0,
                    'ban_ip'          => '',
                    'ban_email'       => $email,
                    'ban_start'       => time(),
                    'ban_end'         => 0,
                    'ban_exclude'     => 0,
                    'ban_reason'      => $admin_reason,
                    'ban_give_reason' => $user_reason,
                ));
                $this->db->sql_query($sql);
            }
        }

        // Deactivate the user immediately
        $sql = 'UPDATE ' . USERS_TABLE . '
            SET user_type = ' . USER_INACTIVE . ',
                user_inactive_reason = 1,
                user_inactive_time = ' . time() . '
            WHERE user_id = ' . $poster_id;
        $this->db->sql_query($sql);

        // Soft-delete all posts by this user (queue + any approved)
        $sql = 'SELECT p.post_id, p.topic_id, p.forum_id, t.topic_first_post_id
            FROM ' . POSTS_TABLE . ' p
            LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id
            WHERE p.poster_id = ' . $poster_id . '
              AND p.post_visibility <> ' . ITEM_DELETED;
        $result = $this->db->sql_query($sql);
        $posts  = $this->db->sql_fetchrowset($result);
        $this->db->sql_freeresult($result);

        foreach ($posts as $p)
        {
            $this->content_visibility->set_post_visibility(
                ITEM_DELETED,
                (int) $p['post_id'],
                (int) $p['topic_id'],
                (int) $p['forum_id'],
                $poster_id,
                time(),
                '',
                ((int) $p['post_id'] === (int) $p['topic_first_post_id']),
                true
            );
        }

        // Write to phpBB moderator log so the action is visible in ACP > Logs > Moderator Log
        $this->log->add(
            'mod',
            ANONYMOUS,
            '0.0.0.0',
            'LOG_PANGRAM_BAN',
            time(),
            array($user_row['username'], $ai_pct),
            0,
            $topic_id,
            $post_id,
            $poster_id
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function clean_post_text($text)
    {
        // Remove BBCode tags: [b], [/b], [url=...], etc.
        $text = preg_replace('/\[\/?\w+(?:=[^\]]+)?\]/u', '', $text);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
