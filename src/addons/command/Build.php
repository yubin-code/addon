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

// 打包插件
class Build extends Command
{

    public function configure()
    {
        $this->setName('addons:build')
          ->addOption('name', 'a', Option::VALUE_REQUIRED, 'addon name', null)  // 插件名字
          ->setDescription('packaging plug-in');
    }

    public function execute(Input $input, Output $output)
    {
      $name = $input->getOption('name') ?: '';
      if (!$name) {
        throw new Exception('Addon name could not be empty');
      }

      // 生成项目名字
      $addonDir = addons_path().$name .DIRECTORY_SEPARATOR;
      //判断插件是否存在
      if (!is_dir($addonDir)) {
        throw new Exception("plug-ins don't exist!");
      }


      $file = addons_path().$name. '.zip';

      // 打包插件
      if (!class_exists('ZipArchive')) {
        throw new Exception('Make sure ZipArchive is installed correctly');
      }

      $zip = new ZipArchive();
      $zip->open($file, ZipArchive::CREATE);
      $files = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($addonDir, \RecursiveDirectoryIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($files as $fileinfo) {
          $filePath = $fileinfo->getPathName();
          $localName = str_replace($addonDir, '', $filePath);
          if ($fileinfo->isFile()) {
              $zip->addFile($filePath, $localName);
          } elseif ($fileinfo->isDir()) {
              $zip->addEmptyDir($localName);
          }
      }
      $zip->close();
      $output->info("successed packaged [${name}] plug-in");
    }

}