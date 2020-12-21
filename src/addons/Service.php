<?php
// +----------------------------------------------------------------------
// | Author: yubin <184622329@qq.com>
// +----------------------------------------------------------------------
// | Date: 2020/12/17
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think\addons;
use ZipArchive;
use fast\Http;
use think\Route;
use think\Exception;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\facade\Db;
use think\addons\middleware\Addons;
use GuzzleHttp\Client;


/**
 * 插件服务
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    protected $addons_path;

    public function register()
    {
        $this->addons_path = $this->getAddonsPath();
        // 加载系统语言包
        Lang::load([
            // 记得修改
            $this->app->getRootPath() . '/vendor/yubin/cDemo/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }

    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 路由脚本
            $execute = '\\think\\addons\\Route::execute';

            // 注册插件公共中间件
            if (is_file($this->app->addons->getAddonsPath() . 'middleware.php')) {
                $this->app->middleware->import(include $this->app->addons->getAddonsPath() . 'middleware.php', 'route');
            }

            // 注册控制器路由
            $route->rule("addons/:addon/[:controller]/[:action]", $execute)->middleware(Addons::class);
            // 自定义路由
            $routes = (array) Config::get('addons.route', []);
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addons'        => $addon,
                            'controller'    => $controller,
                            'action'        => $action,
                            'indomain'      => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addons' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
        });
    }

    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array) $values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->addons_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->addons_path . $name)) {
                continue;
            }
            $addonDir = $this->addons_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 自动载入插件
     * @return bool
     */
    private function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            // 找到插件入口文件
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        Config::set($config, 'addons');
    }

    /**
     * 获取 addons 路径
     * @return string
     */
    public function getAddonsPath()
    {
        // 初始化插件目录
        $addons_path = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($addons_path)) {
            @mkdir($addons_path, 0755, true);
        }
        return $addons_path;
    }

    /**
     * 获取插件的配置信息
     * @param string $name
     * @return array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getConfig();
    }


  /**
     * 远程下载插件
     *
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     * @return  string
     */
    public static function download($name, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();
        $tmpFile = $addonsTempDir . $name . '.zip';
        
        try {
            $client = self::getClient();
            // $response = $client->get('/addon/download', ['query' => array_merge(['name' => $name], $extend)]);
            $response = $client->get('', ['query' => array_merge(['name' => $name], $extend)]);
            $body = $response->getBody();
            $content = $body->getContents();
            if (substr($content, 0, 1) === '{') {
                $json = (array)json_decode($content, true);
                //如果传回的是一个下载链接,则再次下载
                if ($json['data'] && isset($json['data']['url'])) {
                    $response = $client->get($json['data']['url']);
                    $body = $response->getBody();
                    $content = $body->getContents();
                } else {
                    //下载返回错误，抛出异常
                    throw new AddonException($json['msg'], $json['code'], $json['data']);
                }
            }
        } catch (TransferException $e) {
            throw new Exception("Addon package download failed");
        }


        try {

            if ($write = fopen($tmpFile, 'w')) {
                fwrite($write, $content);
                fclose($write);
                return $tmpFile;
            }
        }catch(Exception $e){
            echo "写入失败";
        }
        // throw new Exception("No permission to write temporary files");
    }

    /**
     * 解压插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {
        if (!$name) {
            throw new Exception('Invalid parameters');
        }

        $file = self::getAddonsBackupDir() . $name . '.zip';
        $dir = self::getAddonDir($name);
        
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) {
                throw new Exception('Unable to open the zip file');
            }
            if (! $zip->extractTo($dir)) {
                $zip->close();
                throw new Exception('Unable to extract the file');
            }
            $zip->close();
            return $dir;
        }

        throw new Exception('无法执行解压操作，请确保ZipArchive安装正确');
    }

    /**
     * 验证压缩包、依赖验证
     * @param array $params
     * @return bool
     * @throws Exception
     */
    public static function valid($params = [])
    {
        $client = self::getClient();
        $multipart = [];
        foreach ($params as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        try {
            $response = $client->post('/addon/valid', ['multipart' => $multipart]);
            $content = $response->getBody()->getContents();
        } catch (TransferException $e) {
            throw new Exception("Network error");
        }
        $json = (array)json_decode($content, true);
        if ($json && isset($json['code'])) {
            if ($json['code']) {
                return true;
            } else {
                throw new Exception($json['msg'] ?? "Invalid addon package");
            }
        } else {
            throw new Exception("Unknown data format");
        }
    }


    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        // 获取备份地址
        $dir = self::getAddonDir($name);
        // 获取备份名字
        $file = self::getAddonsBackupDir() . $name . '-backup-' . date("YmdHis") . '.zip';

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $zip->open($file, ZipArchive::CREATE);
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $filePath = $fileinfo->getPathName();
                $localName = str_replace($dir, '', $filePath);
                if ($fileinfo->isFile()) {
                    $zip->addFile($filePath, $localName);
                } elseif ($fileinfo->isDir()) {
                    $zip->addEmptyDir($localName);
                }
            }
            $zip->close();
            return true;
        }
        throw new Exception('无法执行压缩操作，请确保ZipArchive安装正确');
    }

    /**
     * 检测插件是否完整
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        // 判断插件目录是否存在
        if (!$name || !is_dir(self::getAddonDir($name))) {
            throw new Exception('Addon not exists');
        }

        // 获取插件的class
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            throw new Exception("The addon file does not exist");
        }

        // 实例化插件
        $addon = new $addonClass(app());
        if (!$addon->checkInfo()) {
            throw new Exception("The configuration file content is incorrect");
        }
        return true;
    }

    /**
     * 导入SQL
     *
     * @param string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name)
    {
        $sqlFile = self::getAddonDir($name) . '/install.sql';
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::execute($templine);
                    } catch (\PDOException $e) {
                        $e->getMessage();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /**
     * 刷新插件缓存文件
     *
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
    {
        //刷新addons.js
        $addons = get_addon_list();
        $bootstrapArr = [];
        // 等待实现
//         foreach ($addons as $name => $addon) {
//             $bootstrapFile = self::getBootstrapFile($name);
//             if ($addon['state'] && is_file($bootstrapFile)) {
//                 $bootstrapArr[] = file_get_contents($bootstrapFile);
//             }
//         }
//         $addonsFile = ROOT_PATH . str_replace("/", DS, "public/assets/js/addons.js");
//         if ($handle = fopen($addonsFile, 'w')) {
//             $tpl = <<<EOD
// define([], function () {
//     {__JS__}
// });
// EOD;
//             fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
//             fclose($handle);
//         } else {
//             throw new Exception(__("Unable to open file '%s' for writing", "addons.js"));
//         }

//         $file = self::getExtraAddonsFile();

//         $config = get_addon_autoload_config(true);
//         if ($config['autoload']) {
//             return;
//         }

//         if (!is_really_writable($file)) {
//             throw new Exception(__("Unable to open file '%s' for writing", "addons.php"));
//         }

//         if ($handle = fopen($file, 'w')) {
//             fwrite($handle, "<?php\n\n" . "return " . VarExporter::export($config) . ";\n");
//             fclose($handle);
//         } else {
//             throw new Exception(__("Unable to open file '%s' for writing", "addons.php"));
//         }
        return true;
    }

    /**
     * 离线安装
     * @param string $file 插件压缩包
     * @param array  $extend
     */
    public static function local($file, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();

        if (!$file || !$file instanceof \think\File) {
            throw new Exception('No file upload or server upload limit exceeded');
        }

        try {
            validate(['file' => ['fileSize' => 102400000, 'fileExt' => 'zip,fastaddon']])->check(['file' => $file]);
            $file->move($addonsTempDir, $file->getOriginalName());
        } catch (\think\exception\ValidateException $e) {
            throw new Exception($e->getMessage());
        }

        $tmpFile = $addonsTempDir . $file->getOriginalName();

        $info = [];
        try {
            // 打开插件压缩包
            if (!class_exists('ZipArchive')) {
                throw new Exception('无法执行解压操作，请确保ZipArchive安装正确');
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                @unlink($tmpFile);
                $zip->close();
                throw new Exception('Unable to open the zip file');
            }
            
       
            
            $config = self::getInfoIni($zip);

            // 判断插件标识
            $name = isset($config['name']) ? $config['name'] : '';
            if (!$name) {
                throw new Exception('Addon info file data incorrect');
            }

            // 判断插件是否存在
            if (!preg_match("/^[a-zA-Z0-9]+$/", $name)) {
                throw new Exception('Addon name incorrect');
            }

            // 判断新插件是否存在
            $newAddonDir = self::getAddonDir($name);
            if (is_dir($newAddonDir)) {
                throw new Exception('Addon already exists');
            }

            // 追加MD5和Data数据
            $extend['md5'] = md5_file($tmpFile);
            $extend['data'] = $zip->getArchiveComment();
            $extend['unknownsources'] = config('app_debug') && config('fastadmin.unknownsources');
            $extend['faversion'] = config('fastadmin.version');

            $params = array_merge($config, $extend);

            // 压缩包验证、版本依赖判断
            // Service::valid($params);

            //创建插件目录
            @mkdir($newAddonDir, 0755, true);

            // 解压到插件目录
            try {
                if (!$zip->extractTo($newAddonDir)) {
                    $zip->close();
                    throw new Exception('Unable to extract the file');
                }
            } catch (ZipException $e) {
                @unlink($newAddonDir);
                throw new Exception('Unable to extract the file');
            }

            Db::startTrans();
            try {
                //默认禁用该插件
                $info = get_addons_info($name);
                if ($info['state']) {
                    $info['state'] = 0;
                    set_addons_info($name, $info);
                }

                //执行插件的安装方法
                $class = get_addons_class($name);
                if (class_exists($class)) {
                    $addon = new $class(app());
                    $addon->install();
                }
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                @rmdirs($newAddonDir);
                throw new Exception($e->getMessage());
            }
            //导入SQL
            Service::importsql($name);
        } catch (AddonException $e) {
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            $zip->close();
            @unlink($tmpFile);
        }

        $info['config'] = get_addons_config($name) ? 1 : 0;
        return $info;
    }


    /**
     * 安装插件
     *
     * @param string  $name   插件名称
     * @param boolean $force  是否覆盖
     * @param array   $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $force = false, $extend = [])
    {
        // 检查当前插件是否已经安装
        if (!$name || (is_dir(addons_path() . $name) && !$force)) {
            throw new Exception('Addon already exists');
        }

        // 远程下载插件
        $tmpFile = self::download($name, $extend);
        // 解压插件压缩包到插件目录
        $addonDir = self::unzip($name);

        // 移除临时文件
        @unlink($tmpFile);

        try {
            // 检查插件是否完整
            self::check($name);
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        }

        // 默认启用该插件
        $info = get_addons_info($name);
        Db::startTrans();
        try {
            if (!$info['state']) {
                $info['state'] = 1;
                set_addons_info($name, $info);
            }

            // 执行安装脚本
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->install();
            }
            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);
            Db::rollback();
            throw new Exception($e->getMessage());
        }

        // 导入
        Service::importsql($name);

        // 启用插件
        Service::enable($name, true);

        $info['config'] = get_addons_config($name) ? 1 : 0;
        // $info['bootstrap'] = is_file(Service::getBootstrapFile($name));
        return $info;
    }
    /**
     * 卸载插件
     *
     * @param string  $name
     * @param boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(addons_path() . $name)) {
            throw new Exception('Addon not exists');
        }

        // 移除插件基础资源目录
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($destAssetsDir)) {
            $cmd = 'rm -fr '.$destAssetsDir;
            shell_exec($cmd);
        }
        
        // 执行卸载脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 移除插件目录
        rmdirs(addons_path() . $name);
        
        // 刷新
        Service::refresh();
        return true;
    }


    /**
     * 启用.
     *
     * @param string $name  插件名称
     * @param bool   $force 是否强制覆盖
     *
     * @return bool
     */
    public static function enable($name, $force = false)
    {
        if (! $name || ! is_dir(addons_path().$name)) {
            throw new Exception('Addon not exists');
        }

        $addonDir = addons_path().$name.DIRECTORY_SEPARATOR;

        // 复制文件
        $sourceAssetsDir = self::getSourceAssetsDir($name);     // 源文件资源
        $destAssetsDir = self::getDestAssetsDir($name);         // 被复制到资源的文件

        // 创建源文件与资源文件的软连接
        if (is_dir($sourceAssetsDir)) {
            $cmd = 'ln -s '.$sourceAssetsDir .' '.$destAssetsDir;
            shell_exec($cmd);
        }

        //执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, 'enable')) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info = get_addons_info($name);
        $info['state'] = 1;
        unset($info['url']);

        set_addons_info($name, $info);

        // 刷新
        self::refresh();

        return true;
    }

    /**
     * 禁用.
     *
     * @param string $name  插件名称
     * @param bool   $force 是否强制禁用
     *
     * @throws Exception
     *
     * @return bool
     */
    public static function disable($name, $force = false)
    {
        if (! $name || ! is_dir(addons_path().$name)) {
            throw new Exception('Addon not exists');
        }

        // 移除插件基础资源目录
        $destAssetsDir = self::getDestAssetsDir($name);

        if (is_dir($destAssetsDir)) {
            $cmd = 'rm -fr '.$destAssetsDir;
            shell_exec($cmd);
        }
        
        $info = get_addons_info($name);
        $info['state'] = 0;
        unset($info['url']);

        set_addons_info($name, $info);

        // 执行禁用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, 'disable')) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        self::refresh();

        return true;
    }

    /**
     * 升级插件
     *
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
    {
        $info = get_addons_info($name);
        // if ($info['state']) {
        //     throw new Exception('Please disable addon first');
        // }

        $config = get_addons_config($name);
        if ($config) {
            //备份配置
        }

        // 远程下载插件
        $tmpFile = self::download($name, $extend);
        
        // 备份插件文件
        self::backup($name);

        $addonDir = self::getAddonDir($name);

        try {
            // 解压插件
            self::unzip($name);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        if ($config) {
            // 还原配置
            set_addon_config($name, $config);
        }

        // 导入
        Service::importsql($name);

        // 执行升级脚本

        try {
            $addonName = ucfirst($name);
            //创建临时类用于调用升级的方法
            $sourceFile = $addonDir . $addonName . ".php";
            $destFile = $addonDir . $addonName . "Upgrade.php";

            $classContent = str_replace("class {$addonName} extends", "class {$addonName}Upgrade extends", file_get_contents($sourceFile));

            //创建临时的类文件
            file_put_contents($destFile, $classContent);

            $className = "\\addons\\" . $name . "\\" . $addonName . "Upgrade";

            $addon = new $className(app());

            //调用升级的方法
            if (method_exists($addon, "upgrade")) {
                $addon->upgrade();
            }

            //移除临时文件
            @unlink($destFile);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();

        //必须变更版本号
        $info['version'] = isset($extend['version']) ? $extend['version'] : $info['version'];
        $info['config'] = get_addons_config($name) ? 1 : 0;

        return $info;
    }

    /**
     * 获取指定插件的目录
     */
    public static function getAddonDir($name)
    {
        return addons_path().$name.DIRECTORY_SEPARATOR;
    }
    /**
     * 获取插件目标资源文件夹.
     *
     * @param string $name 插件名称
     *
     * @return string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = app()->getRootPath().str_replace('/', DIRECTORY_SEPARATOR, "public/assets/addons/{$name}/");
        if (! is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        return $assetsDir;
    }
    /**
     * 获取插件源资源文件夹.
     *
     * @param string $name 插件名称
     *
     * @return string
     */
    protected static function getSourceAssetsDir($name)
    {
        return addons_path().$name.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR;
    }

    /**
     * 获取请求对象
     * @return Client
     */
    protected static function getClient()
    {
        $options = [
            'base_uri'        => self::getServerUrl(),
            'timeout'         => 30,
            'connect_timeout' => 30,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
                'Referer'          => dirname(request()->root(true)),
                'User-Agent'       => 'FastAddon',
            ]
        ];
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }
        return $client;
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return config('app.serverUrl');
    }

    /**
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $dir = app()->getRootPath().'runtime'.DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 匹配配置文件中info信息
     * @param ZipFile $zip
     * @return array|false
     * @throws Exception
     */
    protected static function getInfoIni($zip)
    {
        $config = [];
        // 读取插件信息
        try {
            $info = $zip->getFromName('info.ini');
            $config = parse_ini_string($info);
        } catch (ZipException $e) {
            throw new Exception('Unable to extract the file');
        }
        return $config;
    }
}