<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------

namespace think\addons\command;

use ZipArchive;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Exception;

class Remove extends Command
{

    public function configure()
    {
        $this->setName('addons:build')
          ->addOption('name', 'a', Option::VALUE_REQUIRED, 'addon name', null)  // 插件名字
          ->setDescription('packaging plug-in');
    }
    
    public function execute(Input $input, Output $output)
    {
      $app = app();
      $name = $input->getOption('name') ?: '';
      
      if (!$name) {
        throw new Exception('Addon name could not be empty');
      }

      // 生成项目名字
      $addonDir = addons_path().$name .DIRECTORY_SEPARATOR;
      
      //判断插件是否存在
      if (!is_dir($addonDir)) {
        throw new Exception("${name} plug-ins don't exist!");
      }

      // 删除插件
      try {
        $app->addons->uninstall($name);
      } catch (Exception $e) {
        throw new Exception($e->getMessage());
      }

      $output->info("The ${name} plug-in was removed successfully");
    }

}
