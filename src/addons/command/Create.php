<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------

namespace think\addons\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Exception;

class Create extends Command
{

    public function configure()
    {
        $this->setName('addons:create')
            ->addOption('name', 'a', Option::VALUE_REQUIRED, 'addon name', null)  // 插件名字
            ->setDescription('create a plug-in project');
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
      //判断目录是否存在
      if (is_dir($addonDir)) {
        throw new Exception("addon already exists!");
      }
      
      mkdir($addonDir, 0755, true);
      mkdir($addonDir . 'controller', 0755, true);
      mkdir($addonDir . 'model', 0755, true);
      mkdir($addonDir . 'view', 0755, true);
      mkdir($addonDir . 'assets', 0755, true);
      
      $app->addons->linkAssetsDir($name);

      $data = [
        'name'               => $name,
        'addon'              => $name,
        'addonClassName'     => ucfirst($name),
      ];
      
      $this->writeToFile("addon", $data, $addonDir . ucfirst($name) . '.php');
      $this->writeToFile("config", $data, $addonDir . 'config.php');
      $this->writeToFile("info", $data, $addonDir . 'info.ini');
      $this->writeToFile("model", $data, $addonDir . 'model' . DIRECTORY_SEPARATOR . $data['addonClassName'].'.php');
      $this->writeToFile("view", $data, $addonDir . 'view' . DIRECTORY_SEPARATOR . 'index/'.DIRECTORY_SEPARATOR.'index.html');
      $this->writeToFile("controller", $data, $addonDir . 'controller' . DIRECTORY_SEPARATOR . 'Index.php');
      $this->writeToFile("css", $data, $addonDir . 'assets' . DIRECTORY_SEPARATOR . 'style.css');
      
      $output->info("Create plug-in Successed!");
    }

   
    /**
     * 写入到文件
     * @param string $name
     * @param array $data
     * @param string $pathname
     * @return mixed
     */
    protected function writeToFile($name, $data, $pathname)
    {
        $search = $replace = [];
        foreach ($data as $k => $v) {
            $search[] = "{%{$k}%}";
            $replace[] = $v;
        }


        $stub = file_get_contents($this->getStub($name));
        $content = str_replace($search, $replace, $stub);

        if (!is_dir(dirname($pathname))) {
            mkdir(strtolower(dirname($pathname)), 0755, true);
        }

        return file_put_contents($pathname, $content);
    }

    /**
     * 获取基础模板
     * @param string $name
     * @return string
     */
    protected function getStub($name)
    {
        return __DIR__ . '/stubs/' . $name . '.stub';
    }
}
