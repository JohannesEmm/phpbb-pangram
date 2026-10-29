<?php
if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = array();
}

$lang = array_merge($lang, array(
    'ACP_PANGRAM_CAT'   => 'Pangram AI Checker',
    'ACP_PANGRAM_TITLE' => 'Pangram AI Post Checker',

    'ACP_PANGRAM_EXPLAIN' => 'Automatically checks the first post from each new user against the Pangram AI detection API. Posts below the approve threshold are approved instantly; posts above the ban threshold result in a permanent ban.',

    'ACP_PANGRAM_SETTINGS'  => 'Settings',

    'ACP_PANGRAM_ENABLED'         => 'Enable Pangram checker',
    'ACP_PANGRAM_API_KEY'         => 'Pangram API key',
    'ACP_PANGRAM_API_KEY_EXPLAIN' => 'Your API key from pangram.com — starts with sk-pg-',

    'ACP_PANGRAM_APPROVE_THRESHOLD'         => 'Auto-approve if AI score below',
    'ACP_PANGRAM_APPROVE_THRESHOLD_EXPLAIN' => 'Posts where Pangram\'s fraction_ai (×100) is at or below this value are automatically approved and the user is activated. Default: 10',

    'ACP_PANGRAM_BAN_THRESHOLD'         => 'Auto-ban if AI score above',
    'ACP_PANGRAM_BAN_THRESHOLD_EXPLAIN' => 'Posts where Pangram\'s fraction_ai (×100) is at or above this value trigger a permanent ban by user_id and email. Default: 90',

    'ACP_PANGRAM_THRESHOLD_ERROR' => 'The approve threshold must be lower than the ban threshold.',

    'LOG_PANGRAM_BAN' => '<strong>User banned:</strong> %1$s (Pangram detected %2$d%% AI)',
));
