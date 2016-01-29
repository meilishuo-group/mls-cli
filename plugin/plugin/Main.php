<?php
/**
 *
 * Date: 16/1/27
 * Time: 下午5:17
 * @author jintanlu <jintanlu@meilishuo.com>
 */

namespace cliplugin\plugin;

use clicomposer\plugin_api\PluginClass;

class Main extends PluginClass
{
    protected $namespace         = 'plugin';
    protected $description       = '插件管理';
    protected $flags             = array();
    protected $options           = array(
        'install'   => array(
            'default'     => 'all',
            'description' => '插件安装：plugin install $plugin_name'
        ),
        'update'    => array(
            'default'     => 'all',
            'description' => '更新插件：plugin update $plugin_name'
        ),
        'remove'    => array(
            'default'     => 'none',
            'description' => '删除插件：plugin remove $plugin_name'
        ),
        'list'      => array(
            'default'     => '.',
            'description' => '插件安装'
        ),
        'installed' => array(
            'default'     => '.',
            'description' => '已安装插件安装'
        ),
        'clean'     => array(
            'default'     => 'all',
            'description' => '清除插件本地缓存'
        )
    );
    protected $version           = '0.1.0';
    private   $repository_folder = '';
    private   $plugin_folder     = '';
    private   $cache_time_file   = '';
    private   $plugin_list       = array();
    private   $cli_config        = array();

    public function exec($arguments)
    {
        $this->repository_folder = __DIR__ . '/../../_plugin_repository';
        $this->cache_time_file   = __DIR__ . '/.cache_time_file';
        $this->plugin_folder     = __DIR__ . '/..';
        $config_str              = file_get_contents(CLI_CONFIG_FILE);
        $this->cli_config        = json_decode($config_str, true) ?: array();

        if (isset($arguments['install']) || isset($arguments['update'])) {
            $flag_install = isset($arguments['install']);
            $tip_name     = $flag_install ? '安装' : '更新';
            $key_name     = $flag_install ? 'install' : 'update';
            $this->downloadPlugins();
            $plugin_list = $this->getPluginList();
            if ($arguments[$key_name] === 'all') {
                $install_plugins = $flag_install ? $plugin_list : $this->getInstallList();
            } else {
                $install_plugins = array_filter(
                    explode(' ', $arguments[$key_name]),
                    function ($item) use ($plugin_list, $tip_name) {
                        if (in_array($item, $plugin_list)) {
                            return true;
                        }
                        if ($item) {
                            echo $tip_name . '失败！插件[' . $item . ']不存在；', PHP_EOL;
                        }

                        return false;
                    }
                );
            }

            foreach ($install_plugins as $plugin_name) {
                $plugin_path = $this->repository_folder . '/' . $plugin_name;
                $target_path = $this->plugin_folder . '/' . $plugin_name;
                if ($flag_install && file_exists($target_path)) {
                    echo $tip_name . '失败！插件[' . $plugin_name . ']已经被安装过了；', PHP_EOL;
                } else {
                    exec('cp -r ' . $plugin_path . ' ' . $this->plugin_folder);
                    $install_plugins = $this->getInstallList();
                    if (!in_array($plugin_name, $install_plugins)) {
                        $this->cli_config['plugins'][] = array(
                            'name' => $plugin_name
                        );
                    }

                    echo $tip_name . '成功！插件[' . $plugin_name . ']；', PHP_EOL;
                }
            }
            file_put_contents(
                CLI_CONFIG_FILE,
                json_encode($this->cli_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            die(PHP_EOL);
        }

        if (isset($arguments['remove'])) {
            $install_plugins = $this->getInstallList();
            $remove_plugins  = explode(' ', $arguments['remove']);

            foreach ($remove_plugins as $rm_plugin) {
                $plugin_index = array_search($rm_plugin, $install_plugins);
                if ($rm_plugin == 'plugin') {
                    echo '删除失败！插件[plugin]不能被删除；', PHP_EOL;
                } elseif ($plugin_index !== false) {
                    $target_path = $this->plugin_folder . '/' . $rm_plugin;
                    exec('rm -rf ' . $target_path);
                    $this->cli_config['plugins'][$plugin_index] = false;
                    echo '删除成功！插件[' . $rm_plugin . ']；', PHP_EOL;
                } else {
                    echo '删除失败！插件[' . $rm_plugin . ']没有安装；', PHP_EOL;
                }
            }

            $this->cli_config['plugins'] = array_filter(
                $this->cli_config['plugins'],
                function ($item) {
                    if ($item === false) {
                        return false;
                    }
                    return true;
                }
            );

            file_put_contents(
                CLI_CONFIG_FILE,
                json_encode($this->cli_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            die(PHP_EOL);
        }

        if (isset($arguments['clean'])) {
            $result = file_put_contents($this->cache_time_file, 0);
            if ($result) {
                die('缓存清除成功' . PHP_EOL . PHP_EOL);
            }
        }

        if (isset($arguments['list'])) {
            $this->downloadPlugins();
            $plugin_list = $this->getPluginList();
            $reg         = '/' . $arguments['list'] . '/';
            $plugin_list = array_filter($plugin_list, function ($item) use ($reg) {
                if (preg_match($reg, $item)) {
                    return true;
                }

                return false;
            });
            sort($plugin_list);
            die(implode(PHP_EOL, $plugin_list) . PHP_EOL . PHP_EOL);
        }

        if (isset($arguments['installed'])) {
            die(implode(PHP_EOL, $this->getInstallList()) . PHP_EOL . PHP_EOL);
        }
    }

    private function downloadPlugins()
    {
        $last_time = 0;
        if (file_exists($this->cache_time_file)) {
            $last_time = intval(trim(file_get_contents($this->cache_time_file)));
        }
        $now_time = time();

        if ($now_time - $last_time < 60 * 10) { //缓存时间10分钟
            return;
        }

        $repository_folder = $this->repository_folder;
        $type              = isset($this->config['type']) ? $this->config['type'] : 'svn';
        if (!isset($this->config['repository'])) {
            die('请配置插件仓库地址' . PHP_EOL . PHP_EOL);
        }

        if ($type === 'svn') {
            if (is_dir($repository_folder)) {
                $result = exec(
                    'svn up ' . $repository_folder
                );
            } else {
                $result = exec(
                    'svn co ' . $this->config['repository'] . ' ' . $repository_folder
                );
            }
        }
        file_put_contents($this->cache_time_file, $now_time);
    }

    private function getPluginList()
    {
        if (!empty($this->plugin_list)) {
            return $this->plugin_list;
        }

        if (is_dir($this->repository_folder) && $dir_p = opendir($this->repository_folder)) {
            while ($dir = readdir($dir_p)) {
                if (strpos($dir, '.') !== 0 && $dir !== 'plugin') {
                    $this->plugin_list[] = $dir;
                }
            }
        }

        return $this->plugin_list;
    }

    private function getInstallList()
    {
        $plugins = isset($this->cli_config['plugins']) ? $this->cli_config['plugins'] : array();
        $plugins = array_map(function ($item) {
            if (is_string($item)) {
                return $item;
            } elseif (is_array($item)) {
                return isset($item['name']) ? $item['name'] : '';
            }

            return '';
        }, $plugins);

        return $plugins;
    }

    private function mkdirs($path, $mod = 0777)
    {
        if (is_dir($path)) {
            return chmod($path, $mod);
        } else {
            $old = umask(0);
            if (mkdir($path, $mod, true) && is_dir($path)) {
                umask($old);

                return true;
            } else {
                umask($old);
            }
        }

        return false;
    }
}