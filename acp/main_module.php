<?php
namespace claude\pangram\acp;

class main_module
{
    public $u_action;

    public function main($id, $mode)
    {
        global $config, $request, $template, $user;

        $user->add_lang_ext('claude/pangram', 'acp/pangram');

        $this->tpl_name   = 'acp_pangram_settings';
        $this->page_title = $user->lang('ACP_PANGRAM_TITLE');

        add_form_key('claude_pangram_settings');

        if ($request->is_set_post('submit'))
        {
            if (!check_form_key('claude_pangram_settings'))
            {
                trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
            }

            $approve_threshold = $request->variable('pangram_approve_threshold', 10);
            $ban_threshold     = $request->variable('pangram_ban_threshold', 90);

            // Enforce sane range and ordering
            $approve_threshold = max(0, min(100, $approve_threshold));
            $ban_threshold     = max(0, min(100, $ban_threshold));
            if ($approve_threshold >= $ban_threshold)
            {
                trigger_error($user->lang('ACP_PANGRAM_THRESHOLD_ERROR') . adm_back_link($this->u_action), E_USER_WARNING);
            }

            $config->set('pangram_enabled',           (int) $request->variable('pangram_enabled', 0));
            $config->set('pangram_api_key',            $request->variable('pangram_api_key', '', true));
            $config->set('pangram_approve_threshold',  $approve_threshold);
            $config->set('pangram_ban_threshold',      $ban_threshold);

            trigger_error($user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
        }

        $template->assign_vars(array(
            'U_ACTION'                  => $this->u_action,
            'PANGRAM_ENABLED'           => !empty($config['pangram_enabled']),
            'PANGRAM_API_KEY'           => isset($config['pangram_api_key']) ? $config['pangram_api_key'] : '',
            'PANGRAM_APPROVE_THRESHOLD' => isset($config['pangram_approve_threshold']) ? (int) $config['pangram_approve_threshold'] : 10,
            'PANGRAM_BAN_THRESHOLD'     => isset($config['pangram_ban_threshold']) ? (int) $config['pangram_ban_threshold'] : 90,
        ));
    }
}
