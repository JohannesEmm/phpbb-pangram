<?php
namespace claude\pangram\migrations;

class install_config extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['pangram_enabled']);
    }

    public static function depends_on()
    {
        return array('\phpbb\db\migration\data\v330\v330');
    }

    public function update_data()
    {
        return array(
            array('config.add', array('pangram_enabled', 1)),
            array('config.add', array('pangram_api_key', '')),
            array('config.add', array('pangram_approve_threshold', 10)),
            array('config.add', array('pangram_ban_threshold', 90)),

            // Category in "Customise"
            array('module.add', array(
                'acp',
                'ACP_CAT_DOT_MODS',
                'ACP_PANGRAM_CAT',
            )),
            // Settings module — provide all info inline so no _info file is needed
            array('module.add', array(
                'acp',
                'ACP_PANGRAM_CAT',
                array(
                    'module_basename' => '\claude\pangram\acp\main_module',
                    'module_langname' => 'ACP_PANGRAM_TITLE',
                    'module_mode'     => 'config',
                    'module_auth'     => 'ext_claude/pangram && acl_a_board',
                ),
            )),
        );
    }

    public function revert_data()
    {
        return array(
            array('config.remove', array('pangram_enabled')),
            array('config.remove', array('pangram_api_key')),
            array('config.remove', array('pangram_approve_threshold')),
            array('config.remove', array('pangram_ban_threshold')),
            array('module.remove', array(
                'acp',
                '\claude\pangram\acp\main_module',
                'config',
            )),
        );
    }
}
