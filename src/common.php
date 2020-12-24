<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------
declare(strict_types=1);

use think\facade\Event;
use think\facade\Route;
use think\facade\Config;
use think\helper\{
    Str, Arr
};

\think\Console::starting(function (\think\Console $console) {
    $console->addCommands([
        'addons:config' => '\\think\\addons\\command\\SendConfig',  // 生成配置文件
        'addons:create' => '\\think\\addons\\command\\Create',      // 创建插件项目
        'addons:build' => '\\think\\addons\\command\\Build',        // 打包插件项目
        'addons:remove' => '\\think\\addons\\command\\Remove',      // 删除插件
    ]);
});

// 插件类库自动载入
spl_autoload_register(function ($class) {

    $class = ltrim($class, '\\');

    $dir = app()->getRootPath();
    $namespace = 'addons';

    if (strpos($class, $namespace) === 0) {
        $class = substr($class, strlen($namespace));
        $path = '';
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;
        
        if (file_exists($dir)) {
            include $dir;
            return true;
        }

        return false;
    }

    return false;

});

if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        $result = Event::trigger($event, $params, $once);

        return join('', $result);
    }
}

if (! function_exists('parseName')) {
    function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);

            return $ucfirst ? ucfirst($name) : lcfirst($name);
        }

        return strtolower(trim(preg_replace('/[A-Z]/', '_\\0', $name), '_'));
    }
}


if (! function_exists('get_addon_list')) {
    /**
     * 获得插件列表
     * @return array
     */
    function get_addon_list()
    {
        $results = scandir(addons_path());
        $list = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file(addons_path() . $name)) {
                continue;
            }
            $addonDir = addons_path() . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            //这里不采用get_addons_info是因为会有缓存
            //$info = get_addons_info($name);
            $info_file = $addonDir . 'info.ini';
            if (!is_file($info_file)) {
                continue;
            }

            $info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            if (!isset($info['name'])) {
                continue;
            }
            $info['url'] = addons_url($name);
            $list[$name] = $info;
        }
        return $list;
    }
}

if (! function_exists('get_addons_class')) {
    /**
     * 获取插件类的类名.
     *
     * @param  string  $name  插件名
     * @param  string  $type  返回命名空间类型
     * @param  string  $class  当前类名
     *
     * @return string
     */
    function get_addons_class($name, $type = 'hook', $class = null)
    {
        $name = parseName($name);

        // 处理多级控制器情况
        if (! is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = parseName(end($class), 1);
            $class = implode('\\', $class);
        } else {
            $class = parseName(is_null($class) ? $name : $class, 1);
        }

        switch ($type) {
            case 'controller':
                $namespace = '\\addons\\'.$name.'\\controller\\'.$class;
                break;
            default:
                $namespace = '\\addons\\'.$name.'\\'.$class;
        }
        
        return class_exists($namespace) ? $namespace : '';
    }
}


if (! function_exists('get_addon_instance')) {
    /**
     * 获取插件的单例.
     *
     * @param  string  $name  插件名
     *
     * @return mixed|null
     */
    function get_addon_instance($name)
    {
        static $_addons = [];
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }
        $class = get_addons_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class(app());

            return $_addons[$name];
        } else {
            return;
        }
    }
}


if (! function_exists('set_addons_info')) {
    /**
     * 设置基础配置信息.
     *
     * @param  string  $name  插件名
     * @param  array  $array  配置数据
     *
     * @throws Exception
     * @return bool
     */
    function set_addons_info($name, $array)
    {
        $file = addons_path().$name.DIRECTORY_SEPARATOR.'info.ini';
        $addon = get_addon_instance($name);
        $array = $addon->setInfo($name, $array);
        if (! isset($array['name']) || ! isset($array['title']) || ! isset($array['version'])) {
            throw new Exception('插件配置写入失败');
        }
        $res = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $res[] = "[$key]";
                foreach ($val as $skey => $sval) {
                    $res[] = "$skey = ".(is_numeric($sval) ? $sval : $sval);
                }
            } else {
                $res[] = "$key = ".(is_numeric($val) ? $val : $val);
            }
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, implode("\n", $res)."\n");
            fclose($handle);
            //清空当前配置缓存
            Config::set([$name => null], 'addoninfo');
        } else {
            throw new Exception('文件没有写入权限');
        }

        return true;
    }

}


if (!function_exists('addons_path')) {
    /**
     * 获取插件目录
     * @param string $name 插件名
     * @return array
     */
    function addons_path()
    {
        $dir = app()->getRootPath();
        $namespace = 'addons';

        return $dir.$namespace.DIRECTORY_SEPARATOR;
    }
}

/**
 * 获取插件类的配置值值
 *
 * @param  string  $name  插件名
 *
 * @return array
 */
function get_addons_config($name)
{
    $addon = get_addon_instance($name);
    if (! $addon) {
        return [];
    }

    return $addon->getConfig($name);
}

if (!function_exists('get_addons_info')) {
    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return array
     */
    function get_addons_info($name)
    {
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getInfo();
    }
}

if (!function_exists('get_addons_instance')) {
    /**
     * 获取插件的单例
     * @param string $name 插件名
     * @return mixed|null
     */
    function get_addons_instance($name)
    {

        static $_addons = [];
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }
        $class = get_addons_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class(app());
            return $_addons[$name];
        } else {
            return null;
        }
    }
}


if (!function_exists('addons_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function addons_url($url = '', $param = [], $suffix = true, $domain = false)
    {
        $request = app('request');
        if (empty($url)) {
            // 生成 url 模板变量
            $addons = $request->addon;
            $controller = $request->controller();
            $controller = str_replace('/', '.', $controller);
            $action = $request->action();
        } else {
            $url = Str::studly($url);
            $url = parse_url($url);
            if (isset($url['scheme'])) {
                $addons = strtolower($url['scheme']);
                $controller = $url['host'];
                $action = trim($url['path'], '/');
            } else {
                $route = explode('/', $url['path']);
                $addons = $request->addon;
                $action = array_pop($route);
                $controller = array_pop($route) ?: $request->controller();
            }
            $controller = Str::snake((string)$controller);

            /* 解析URL带的参数 */
            if (isset($url['query'])) {
                parse_str($url['query'], $query);
                $param = array_merge($query, $param);
            }
        }
        return Route::buildUrl("@addons/{$addons}/{$controller}/{$action}", $param)->suffix($suffix)->domain($domain);
    }
}

if (!function_exists('rmdirs')) {
    /**
     * 删除文件夹.
     *
     * @param  string  $dirname  目录
     * @param  bool  $withself  是否删除自身
     *
     * @return bool
     */
    function rmdirs($dirname, $withself = true)
    {
        if (! is_dir($dirname)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        if ($withself) {
            @rmdir($dirname);
        }

        return true;
    }
}

if (!function_exists('liveExecuteCommand')) {
/**
 * Execute the given command by displaying console output live to the user.
 *  @param  string  cmd          :  command to be executed
 *  @return array   exit_status  :  exit status of the executed command
 *                  output       :  console output of the executed command
 */
function liveExecuteCommand($cmd){
    while (@ob_end_flush()); // end all output buffers if any
    $proc = popen("$cmd 2>&1 ; echo Exit status : $?", 'r');
    $live_output     = "";
    $complete_output = "";
    while (!feof($proc))
    {
      $live_output     = fread($proc, 4096);
      $complete_output = $complete_output . $live_output;
      echo "$live_output";
      @ flush();
    }
  
    pclose($proc);
  
    // get exit status
    preg_match('/[0-9]+$/', $complete_output, $matches);
  
    // return exit status and intended output
    return array (
      'exit_status'  => intval($matches[0]),
      'output'       => str_replace("Exit status : " . $matches[0], '', $complete_output)
    );
  }

}