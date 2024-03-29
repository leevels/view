<?php

declare(strict_types=1);

namespace Leevel\View;

use Leevel\Filesystem\Helper\CreateFile;
use Leevel\Support\Stack;

/**
 * 分析模板.
 */
class Parser
{
    /**
     * 编译器.
     */
    protected Compiler $compiler;

    /**
     * 成对节点栈.
     */
    protected ?Stack $nodeStack = null;

    /**
     * 编译器.
     */
    protected array $compilers = [];

    /**
     * 分析器.
     */
    protected array $parses = [];

    /**
     * 分析器定界符.
     */
    protected array $tags = [
        // 全局
        'global' => [
            'left' => '{%',
            'right' => '%}',
        ],

        // 代码
        'code' => [
            'left' => '{{',
            'right' => '}}',
        ],

        // 节点
        'node' => [
            'left' => '{%',
            'right' => '%}',
        ],

        // 反向
        'revert' => [],

        // 全局反向
        'globalrevert' => [],
    ];

    /**
     * 模板树结构.
     */
    protected array $themeTree = [];

    /**
     * 模板项结构.
     */
    protected static array $themeStruct = [
        // 原模板
        'source' => '',
        'content' => '',

        // 编译器
        'compiler' => null,
        'children' => [],
        'position' => [],
    ];

    /**
     * 当前编译源文件.
     */
    protected ?string $sourceFile = null;

    /**
     * 当前编译缓存文件.
     */
    protected ?string $cachePath = null;

    /**
     * 构造函数.
     *
     * - 模板引擎分析器和编译器实现技术原理来源于 Jecat 框架.
     * - 一款无与伦比的技术大餐，有幸在 2010 接触到这个框架，通过这个框架学到了很多.
     * - 它的模板引擎实现了可以将 GLADE3 的 xml 文件编译成 PHP-Gtk 的组件，也支持 Html 编译，非常震撼.
     *
     * @see https://github.com/JeCat/framework
     */
    public function __construct(Compiler $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * 注册视图编译器.
     */
    public function registerCompilers(): self
    {
        foreach ($this->compiler->getCompilers() as $compiler) {
            foreach ((array) $compiler[1] as $name) {
                $this->registerCompiler($compiler[0], $name, $compiler[2]);
            }
        }

        return $this;
    }

    /**
     * 注册视图分析器.
     */
    public function registerParsers(): self
    {
        foreach ($this->tags as $key => $_) {
            $this->registerParser($key);
        }

        return $this;
    }

    /**
     * 执行编译.
     *
     * @throws \InvalidArgumentException
     */
    public function doCompile(string $file, ?string $cachePath = null, bool $isContent = false): string
    {
        // 源码
        if (false === $isContent) {
            if (!is_file($file)) {
                throw new \InvalidArgumentException(sprintf('File %s is not exits.', $file));
            }

            $cache = file_get_contents($file) ?: '';
            $this->sourceFile = $file;
            $this->cachePath = $cachePath;
        } else {
            $cache = $file;
        }

        // 逐个载入分析器编译模板
        foreach ($this->parses as $parser) {
            // 清理对象 & 构建顶层树对象
            $this->clearThemeTree();

            $theme = [
                'source' => $cache,
                'content' => $cache,
                'position' => $this->getPosition($cache, '', 0),
            ];
            $theme = $this->normalizeThemeStruct($theme);
            $this->topTheme($theme);

            // 分析模板生成模板树
            $this->{$parser.'Parse'}($cache);

            // 编译模板树
            $cache = $this->compileThemeTree();
        }

        // 生成编译文件
        if (null !== $cachePath) {
            $this->makeCacheFile($cachePath, $cache);
        }

        return $cache;
    }

    /**
     * code 编译编码，后还原
     */
    public static function revertEncode(string $content): string
    {
        $rand = random_int(1000000, 9999999);

        return "__##revert##START##{$rand}@".
            base64_encode($content).
            '##END##revert##__';
    }

    /**
     * tagself 编译编码，后还原
     */
    public static function globalEncode(string $content): string
    {
        $rand = random_int(1000000, 9999999);

        return "__##global##START##{$rand}@".
            base64_encode($content).
            '##END##global##__';
    }

    /**
     * 全局编译器 tagself.
     */
    protected function globalParse(string &$compiled): void
    {
        $tag = $this->getTag('global');
        if (preg_match_all(
            "/{$tag['left']}\\s*tagself\\s*{$tag['right']}(.+?){$tag['left']}\\s*:\\s*tagself\\s*{$tag['right']}/isx",
            $compiled,
            $res
        )) {
            $startPos = 0;
            foreach ($res[1] as $index => $encode) {
                $source = trim($res[0][$index]);
                $content = trim($res[1][$index]);
                $theme = [
                    'source' => $source,
                    'content' => $content,
                    'compiler' => 'global',
                    'children' => [],
                ];
                $theme['position'] = $this->getPosition($compiled, $source, $startPos);
                $startPos = $theme['position']['end'] + 1;
                $theme = $this->normalizeThemeStruct($theme);
                $this->addTheme($theme);
            }
        }
    }

    /**
     * code 方式分析器.
     */
    protected function codeParse(string &$compiled): void
    {
        $names = [];
        foreach ($this->compilers['code'] as $compilers => $tag) {
            $names[] = $this->escapeRegexCharacter($compilers);
        }
        $names = implode('|', $names);

        $tag = $this->getTag('code');
        $regex = '/'.$tag['left']."\\s*({$names})(|.+?)".$tag['right'].'/s';
        if (preg_match_all($regex, $compiled, $res)) {
            $startPos = 0;
            foreach ($res[0] as $index => &$source) {
                $type = trim($res[1][$index]);
                !$type && $type = '/';
                $content = trim($res[2][$index]);
                $theme = [
                    'source' => $source,
                    'content' => $content,
                    'compiler' => $this->compilers['code'][$type].'Code',
                    'children' => [],
                ];
                $theme['position'] = $this->getPosition($compiled, $source, $startPos);
                $startPos = $theme['position']['end'] + 1;
                $theme = $this->normalizeThemeStruct($theme);
                $this->addTheme($theme);
            }
        }
    }

    /**
     * node 分析器.
     */
    protected function nodeParse(string &$compiled): void
    {
        $this->normalizeNodeParse($compiled);
    }

    /**
     * 格式化 node 分析器.
     */
    protected function normalizeNodeParse(string &$compiled): void
    {
        $this->findNodeTag($compiled);
        $this->packNode($compiled);
    }

    /**
     * code 还原分析器.
     */
    protected function revertParse(string &$compiled): void
    {
        if (preg_match_all(
            '/__##revert##START##\d+@(.+?)##END##revert##__/',
            $compiled,
            $res
        )) {
            $startPos = 0;
            foreach ($res[1] as $index => $encode) {
                $source = $res[0][$index];
                $theme = [
                    'source' => $source,
                    'content' => $encode,
                    'compiler' => 'revert',
                    'children' => [],
                ];
                $theme['position'] = $this->getPosition($compiled, $source, $startPos);
                $startPos = $theme['position']['end'] + 1;
                $theme = $this->normalizeThemeStruct($theme);
                $this->addTheme($theme);
            }
        }
    }

    /**
     * tagself 还原分析器.
     */
    protected function globalrevertParse(string &$compiled): void
    {
        if (preg_match_all(
            '/__##global##START##\d+@(.+?)##END##global##__/',
            $compiled,
            $res
        )) {
            $startPos = 0;
            foreach ($res[1] as $index => $encode) {
                $source = $res[0][$index];
                $content = $res[1][$index];
                $theme = [
                    'source' => $source,
                    'content' => $content,
                    'compiler' => 'globalrevert',
                    'children' => [],
                ];
                $theme['position'] = $this->getPosition($compiled, $source, $startPos);
                $startPos = $theme['position']['end'] + 1;
                $theme = $this->normalizeThemeStruct($theme);
                $this->addTheme($theme);
            }
        }
    }

    /**
     * 查找成对节点.
     */
    protected function findNodeTag(string &$compiled): void
    {
        // 设置一个栈
        $this->nodeStack = new Stack(['array']);

        // 判断是那种编译器
        $nodeType = 'node';

        // 所有一级节点名
        $names = [];
        foreach ($this->compilers[$nodeType] as $compilers => $tag) {
            $names[] = $this->escapeRegexCharacter($compilers);
        }
        $names = implode('|', $names);

        $tag = $this->getTag($nodeType);
        $regex = "/{$tag['left']}\\s*(\\:?)\\s*(({$names})(:[^\\s\\}]+)?)(\\s[^%\\}]*?)?{$tag['right']}/isx";

        // 标签名称位置
        $nodeNameIndex = 2;

        // 标签顶级名称位置
        $nodeTopNameIndex = 3;

        // 尾标签冒号位置
        $tailSlasheIndex = 1;

        // 标签属性位置
        $tagAttributeIndex = 5;

        $compiler = $this->compilers['node'];

        // 依次创建标签对象
        if (preg_match_all($regex, $compiled, $res)) {
            $startPos = 0;
            foreach ($res[0] as $index => &$tagSource) {
                $nodeName = $res[$nodeNameIndex][$index];
                $nodeTopName = $res[$nodeTopNameIndex][$index];
                $nodeType = ':' === $res[$tailSlasheIndex][$index] ? 'tail' : 'head';

                // 将节点名称统一为小写
                $nodeName = strtolower($nodeName);
                $nodeTopName = strtolower($nodeTopName);
                $theme = [
                    'source' => $tagSource,
                    'name' => $compiler[$nodeTopName],
                    'type' => $nodeType,
                ];

                // 头标签的属性
                if ('head' === $nodeType) {
                    $theme['attribute'] = $res[$tagAttributeIndex][$index];
                } else {
                    $theme['attribute'] = '';
                }
                $theme['content'] = $theme['attribute'];
                $theme['position'] = $this->getPosition($compiled, $tagSource, $startPos);
                $startPos = $theme['position']['end'] + 1;
                $theme = $this->normalizeThemeStruct($theme);

                // 加入到标签栈
                $this->nodeStack->push($theme);
            }
        }
    }

    /**
     * 装配节点.
     *
     * @throws \InvalidArgumentException
     */
    protected function packNode(string &$compiled): void
    {
        $nodeTag = $this->compiler->getNodeTagHelp();
        $compiler = 'Node';

        // 尾标签栈
        $tailStack = new Stack(['array']);

        // 载入节点属性分析器 & 依次处理所有标签
        while (null !== ($tag = $this->nodeStack->pop())) {
            // 尾标签，加入到尾标签中
            if ('tail' === $tag['type']) {
                $tailStack->push($tag);

                continue;
            }

            // 从尾标签栈取出一项
            // 单标签节点
            $tailTag = $tailStack->pop();
            if (!$tailTag || !$this->findHeadTag($tag, $tailTag)) {
                if (true !== $nodeTag[$tag['name']]['single']) {
                    // @phpstan-ignore-next-line
                    throw new \InvalidArgumentException(sprintf('%s type nodes must be used in pairs, and no corresponding tail tags are found.', $tag['name']).
                        PHP_EOL.$this->getLocation($tag['position']));
                }

                // 退回栈中
                if ($tailTag) {
                    $tailStack->push($tailTag);
                }

                $themeNode = [
                    'content' => $tag['content'],
                    'compiler' => $tag['name'].$compiler,
                    'source' => $tag['source'],
                    'name' => $tag['name'],
                ];
                $themeNode['position'] = $tag['position'];
                $themeNode = $this->normalizeThemeStruct($themeNode);
            }

            // 成对标签
            else {
                // 头尾标签中间为整个标签内容
                $start = (int) $tag['position']['start'];
                $len = (int) $tailTag['position']['end'] - $start + 1;
                $source = substr($compiled, $start, $len);
                $themeNode = [
                    'content' => $source,
                    'compiler' => $tag['name'].$compiler,
                    'source' => $source,
                    'name' => $tag['name'],
                ];
                $themeNode['position'] = $this->getPosition($compiled, $source, $start);
                $themeNode = $this->normalizeThemeStruct($themeNode);

                // 标签 body
                $start = (int) $tag['position']['end'] + 1;
                $len = (int) $tailTag['position']['start'] - $start;
                if ($len > 0) {
                    $body = substr($compiled, $start, $len);
                    $themeBody = [
                        'content' => $body,
                        'compiler' => null,
                        'source' => $body,
                        'is_body' => true,
                    ];
                    $themeBody['position'] = $this->getPosition($compiled, $body, $start);
                    $themeBody = $this->normalizeThemeStruct($themeBody);
                    $themeNode = $this->addThemeTree($themeNode, $themeBody);
                }
            }

            // 标签属性
            $themeAttr = [
                'content' => $tag['content'],
                'compiler' => 'attributeNode',
                'source' => $tag['source'],
                'attribute_list' => [],
                'is_attribute' => true,
                'parent_name' => $themeNode['name'],
            ];
            $themeAttr['position'] = $this->getPosition($compiled, $tag['source'], 0);
            $themeAttr = $this->normalizeThemeStruct($themeAttr);
            $themeNode = $this->addThemeTree($themeNode, $themeAttr);

            // 将模板数据加入到树结构中
            $this->addTheme($themeNode);
        }
    }

    /**
     * 查找 node 标签.
     */
    protected function findHeadTag(array $tag, array $tailTag): bool
    {
        return preg_match("/^{$tailTag['name']}/i", $tag['name']) > 0;
    }

    /**
     * 注册分析器.
     */
    protected function registerParser(string $tag): void
    {
        $this->parses[] = $tag;
    }

    /**
     * 注册编译器.
     */
    protected function registerCompiler(string $type, string $name, string $tag): void
    {
        $this->compilers[$type][$name] = $tag;
    }

    /**
     * 逐个编译模板树.
     */
    protected function compileThemeTree(): string
    {
        $cache = '';
        foreach ($this->themeTree as $theme) {
            $this->compileTheme($theme);
            $cache .= $theme['content'];
        }

        return $cache;
    }

    /**
     * 分析模板调用编译器编译.
     */
    protected function compileTheme(array &$theme): void
    {
        foreach ($theme['children'] as $key => $value) {
            $source = $value['source'];

            // 编译子对象
            $this->compileTheme($value);
            $theme['children'][$key] = $value;

            // 置换对象
            if (false !== ($start = strpos($theme['content'], $source))) {
                $len = $value['position']['end'] - $value['position']['start'] + 1;
                $theme['content'] = substr_replace($theme['content'], $value['content'], $start, $len);
            }
        }

        // 编译自身
        if ($theme['compiler']) {
            $compilers = $theme['compiler'].'Compiler';
            $this->compiler->{$compilers}($theme);
        }
    }

    /**
     * 创建缓存文件.
     */
    protected function makeCacheFile(string $cachePath, string &$compiled): void
    {
        $content = '<?php /* '.date('Y-m-d H:i:s').' */ ?>'.PHP_EOL.$compiled;
        CreateFile::handle($cachePath, $content);
    }

    /**
     * 取得模板分析器定界符.
     */
    protected function getTag(string $type): array
    {
        return $this->tags[$type];
    }

    /**
     * 将模板结构加入树结构中去.
     */
    protected function addTheme(array $theme): void
    {
        $top = &$this->themeTree[0];
        $top = $this->addThemeTree($top, $theme);
    }

    /**
     * 清理模板树对象.
     */
    protected function clearThemeTree(): void
    {
        $this->themeTree = [];
    }

    /**
     * 添加顶层树对象.
     */
    protected function topTheme(array $theme): void
    {
        $this->themeTree[] = $theme;
    }

    /**
     * 将新的模板加入到树结构中去.
     */
    protected function addThemeTree(array $top, array $new): array
    {
        $result = [];
        foreach ($top['children'] as $child) {
            if ($new) {
                $relative = $this->positionRelative($new['position'], $child['position']);

                switch ($relative) {
                    // 新增的和上次处于平级关系直接加入上级的 children 容器中
                    // new 在前 child 在后面
                    case 'front':
                        $result[] = $new;
                        $result[] = $child;
                        $new = null;

                        break;
                        // 新增的和上次处于平级关系直接加入上级的 children 容器中
                        // child 在前 new 在后面
                    case 'behind':
                        $result[] = $child;

                        break;
                        // new 处于 child 内部
                        // new 在 child 内部
                    case 'in':
                        $child = $this->addThemeTree($child, $new);
                        $result[] = $child;
                        $new = null;

                        break;
                        // child 处于 new 内部
                        // child 在 new 内部
                    case 'out':
                        $new = $this->addThemeTree($new, $child);

                        break;
                }
            } else {
                $result[] = $child;
            }
        }

        if ($new) {
            $result[] = $new;
        }

        $top['children'] = $result;

        return $top;
    }

    /**
     * 分析匹配标签的位置.
     *
     * - 返回值 数组['start'] 标签开始的位置（字节数）
     * - 返回值 数组['end'] 标签结束的位置（字节数）
     * - 返回值 数组['start_line'] 标签开始的行（行数）
     * - 返回值 数组['end_line'] 标签结束的行（行数）
     * - 返回值 数组['start_in'] 标签开始的所在的行的起始字节数
     * - 返回值 数组['end_in'] 标签结束的所在的行的起始字节数
     */
    protected function getPosition(string $content, string $find, int $start): array
    {
        $data = [];

        // 空
        if (empty($find)) {
            $data['start'] = -1;
            $data['end'] = -1;
            $data['start_line'] = -1;
            $data['end_line'] = -1;
            $data['start_in'] = -1;
            $data['end_in'] = -1;

            return $data;
        }

        // 起止字节位置
        $start = strpos($content, $find, $start) ?: 0;
        $end = $start + \strlen($find) - 1;

        // 起止行数
        $startLine = $start <= 0 ? 0 : substr_count($content, PHP_EOL, 0, $start);
        $endLine = $end <= 0 ? 0 : substr_count($content, PHP_EOL, 0, $end);

        // 匹配模块范围圈（在这个字节里面的都是它的子模快）
        // 找到开始和结束的地方就确定了这个模块所在区域范围.
        // 起点的行首换行位置 && 结束点的行首位置
        $lineStartFirst = strrpos(substr($content, 0, $start), PHP_EOL) + 1;
        $lineEndFirst = strrpos(substr($content, 0, $end), PHP_EOL) + 1;
        $startIn = $start - $lineStartFirst;
        $endIn = $end - $lineEndFirst;

        // 返回结果
        $data['start'] = $start;
        $data['end'] = $end;
        $data['start_line'] = $startLine;
        $data['end_line'] = $endLine;
        $data['start_in'] = $startIn;
        $data['end_in'] = $endIn;

        return $data;
    }

    /**
     * 对比两个模板相对位置.
     *
     * - 这个和两个时间段之间的关系一样，其中交叉在模板引擎中是不被支持，因为无法实现.
     * - 除掉交叉，剩下包含、被包含、前面和后面，通过位置组装成一颗树结构.
     * - 返回值 front 第一个在第二个前面
     * - 返回值 behind 第一个在第二个后面
     * - 返回值 in 第一个在第二里面，成为它的子模板
     * - 返回值 out 第一个在第一个里面，成为它的子模板
     *
     * @throws \InvalidArgumentException
     */
    protected function positionRelative(array $value, array $beyond): string
    {
        // 第一个匹配的标签在第二个前面
        // 条件：第一个结束字节位置 <= 第二个开始位置
        // 示例图：
        //
        // {% if %}
        // value
        // {% :if %}
        //
        // {% for %}
        // beyond
        // {% :for %}
        if ($value['end'] <= $beyond['start']) {
            return 'front';
        }

        // 第一个匹配的标签在第二个后面
        // 条件：第一个开始字节位置 >= 第二个结束位置
        // 示例图：
        //
        // {% for %}
        // beyond
        // {% :for %}
        //
        // {% if %}
        // value
        // {% :if %}
        if ($value['start'] >= $beyond['end']) {
            return 'behind';
        }

        // 第一个匹配的标签在第二个里面
        // 条件：第一个开始字节位置 >= 第二个开始位置
        // 示例图：
        //
        // {% for %}
        // beyond
        //
        // {% if %}
        // value
        // {% :if %}
        //
        // {% :for %}
        if ($value['start'] >= $beyond['start']
            && $value['end'] <= $beyond['end']) {
            return 'in';
        }

        // 第一个匹配的标签在第二个外面
        // 条件：第一个开始字节位置 <= 第二个开始位置
        // 示例图：
        //
        // {% if %}
        // value
        //
        // {% for %}
        // beyond
        // {% :for %}
        //
        // {% :if %}
        if ($value['start'] <= $beyond['start']
            && $value['end'] >= $beyond['end']) {
            return 'out';
        }

        // 交叉（两个时间段相互关系）
        throw new \InvalidArgumentException('Template engine tag library does not support cross.');
    }

    /**
     * 整理模板项结构.
     */
    protected function normalizeThemeStruct(array $theme): array
    {
        return array_merge(self::$themeStruct, $theme);
    }

    /**
     * 转义正则表达式特殊字符.
     */
    protected function escapeRegexCharacter(string $txt): string
    {
        return preg_quote($txt, '/');
    }

    /**
     * 取得模板位置.
     */
    protected function getLocation(array $position): string
    {
        return sprintf(
            'Line:%s; column:%s; file:%s.',
            $position['start_line'],
            $position['start_in'],
            $this->sourceFile ?: null
        ).
            ($this->sourceFile ? $this->getLocationSource($this->sourceFile, $position) : null);
    }

    /**
     * 取得模板位置源码.
     */
    protected function getLocationSource(string $sourceFile, array $position): string
    {
        $content = substr(file_get_contents($sourceFile) ?: '', $position['start'], $position['end']);
        $line = explode(PHP_EOL, htmlentities($content));
        $line[] = '<div class="template-key">'.array_pop($line).'</div>';

        return '<pre><code>'.implode(PHP_EOL, $line).'</code></pre>';
    }
}
