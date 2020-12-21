<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------

namespace think\addons\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;

class SendConfig extends Command
{

    public function configure()
    {
        $this->setName('addons:c')
             ->setDescription('send config to config folder');
    }

    public function execute(Input $input, Output $output)
    {
      echo "创建项目中";
    }

}
