<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think;

use think\App;
use think\helper\Str;
use think\facade\Config;
use think\facade\View;

abstract class Addons
{
    // app 容器
    protected $app;
    // 请求对象
    protected $request;
    // 当前插件标识
    protected $name;
    // 插件路径
    protected $addon_path;
    
    // 插件配置
    protected $addon_config;
    // 插件信息
    protected $addon_info;
    
    // 当前错误信息
    protected $error;
    // 插件目录
    public $addons_path = '';
    // 插件配置作用域
    protected $configRange = 'addonconfig';
    // 插件信息作用域
    protected $infoRange = 'addoninfo';
    /**
     * 插件构造函数
     * Addons constructor.
     * @param \think\App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->name = $this->getName();
        $this->addon_path = $app->addons->getAddonsPath() . $this->name . DIRECTORY_SEPARATOR;
        $this->addon_config = "addon_{$this->name}_config";
        $this->addon_info = "addon_{$this->name}_info";

        // 设置模版变量
        $tpl_replace_string = Config::get('view.tpl_replace_string');
        $tpl_replace_string['__ADDON__'] = "/assets/addons/".$this->name;
        
        // 模版引擎配置修改
        View::config([
            'view_path' => $this->addon_path . 'view' . DIRECTORY_SEPARATOR,
            'tpl_replace_string' => $tpl_replace_string
        ]);
        $this->initialize();
    }

    // 初始化
    protected function initialize(){}

    /**
     * 获取插件标识
     * @return mixed|null
     */
    final protected function getName()
    {
        $class = get_class($this);
        list(, $name, ) = explode('\\', $class);
        $this->request->addon = $name;

        return $name;
    }

    /**
     * 检查基础配置信息是否完整
     * @return bool
     */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'intro', 'author', 'version', 'state'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 设置插件信息数据.
     *
     * @param $name
     * @param array $value
     *
     * @return array
     */
    final public function setInfo($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $info = $this->getInfo($name);
        $info = array_merge($info, $value);
        Config::set([$name => $info], $this->infoRange);

        return $info;
    }
    /**
     * 插件基础信息
     * @return array
     */
    final public function getInfo()
    {
        $info = Config::get($this->addon_info ?? "-", []);
        if ($info) {
            return $info;
        }
        // 文件属性
        $info = $this->info ?? [];
        // 文件配置
        $info_file = $this->addon_path . 'info.ini';
        if (is_file($info_file)) {
            $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            $_info['url'] = addons_url();
            $info = array_merge($_info, $info);
        }

        Config::set($info, $this->addon_info);
        return isset($info) ? $info : [];
    }

    /**
     * 获取配置信息
     * @param bool $type 是否获取完整配置
     * @return array|mixed
     */
    final public function getConfig($type = false)
    {
        $config = Config::get($this->addon_config, []);
        if ($config) {
            return $config;
        }
        $config_file = $this->addon_path . 'config.php';
        if (is_file($config_file)) {
            $temp_arr = (array)include $config_file;
            if ($type) {
                return $temp_arr;
            }

            foreach ($temp_arr as $key => $value) {
                $config[$key] = $value['value'];
            }
            unset($temp_arr);
        }
        Config::set($config, $this->addon_config);

        return $config;
    }

    /**
     * 获取 composer.json 中的信息
     */
    final public function getComposer(){
        $composer = $this->app->getRootPath().'composer.json';
        return json_decode(file_get_contents($composer), true);
    }

    // /**
    //  * 判断是否安装了某个包
    //  */
    // final public function isPack($pack = ''){
    //     $info = $this->getComposer();
    //     $require = $info['require'];
    //     return array_key_exists($pack,$require);
    // }
    // /**
    //  * 安装包
    //  */
    // final public function installPack($pack = []){
    //     foreach($pack as $key => $val){
    //         $package = ""; 
    //         // 判断是否没有版本要求
    //         if(is_numeric($key)){
    //             if($this->isPack($val)){
    //                 continue;
    //             }
    //             $package = $val;
    //         }

    //         if($this->isPack($key)){
    //             continue;
    //         }
    //         $package = "{$key}:{$val}";
    //     }
    // }
    //必须实现安装
    abstract public function install();

    //必须卸载插件方法
    abstract public function uninstall();
}