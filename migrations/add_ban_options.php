<?php
namespace claude\pangram\migrations;

class add_ban_options extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['pangram_ban_delete_mode']);
    }

    public static function depends_on()
    {
        return array('\claude\pangram\migrations\install_config');
    }

    public function update_data()
    {
        return array(
            // 0 = soft delete (default), 1 = permanent delete
            array('config.add', array('pangram_ban_delete_mode', 0)),
            // 0 = remove from queue (default), 1 = keep in queue
            array('config.add', array('pangram_ban_keep_in_queue', 0)),
        );
    }

    public function revert_data()
    {
        return array(
            array('config.remove', array('pangram_ban_delete_mode')),
            array('config.remove', array('pangram_ban_keep_in_queue')),
        );
    }
}
