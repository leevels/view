<?php

declare(strict_types=1);

namespace Leevel\View\Console;

use Leevel\Console\Command;
use Leevel\Kernel\IApp;
use Leevel\View\Compiler;
use Leevel\View\Html;
use Leevel\View\Manager;
use Leevel\View\Parser;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;

/**
 * 视图缓存.
 */
class Cache extends Command
{
    /**
     * 命令名字.
     */
    protected string $name = 'view:cache';

    /**
     * 命令行描述.
     */
    protected string $description = 'Compile all view files';

    /**
     * 应用.
     */
    protected IApp $app; /** @phpstan-ignore-line */

    /**
     * 视图分析器.
     */
    protected Parser $parser; /** @phpstan-ignore-line */

    /**
     * 视图 HTML 仓储.
     */
    protected Html $html; /** @phpstan-ignore-line */

    /**
     * 响应命令.
     */
    public function handle(IApp $app): int
    {
        $this->app = $app;
        $this->parser = $this->createParser();
        $this->html = $this->getHtmlView();

        $this->line('Start to cache view.');

        foreach ($this->paths() as $path) {
            $this->compiles($this->findFiles([$path]), $path);
        }

        $this->info('View files cache succeed.');

        return self::SUCCESS;
    }

    /**
     * 编译视图文件.
     */
    protected function compiles(Finder $finder, string $path): void
    {
        if (0 === \count($finder)) {
            $this->comment(sprintf('Compile files not found in path `%s` and skipped.', $path));

            return;
        }

        $this->info(sprintf('Start to compiles path `%s`', $path));

        $progressBar = new ProgressBar(new ConsoleOutput(), \count($finder));
        $progressBar->setFormat('[View:cache]%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        foreach ($finder as $file) {
            $progressBar->advance();
            $this->parser->doCompile(
                $file->getRealPath(),
                $this->html->parseCachePath($file->getRealPath())
            );
        }

        $progressBar->finish();
        $this->line('');
    }

    /**
     * 查找视图目录中的视图文件.
     */
    protected function findFiles(array $paths): Finder
    {
        return (new Finder())
            ->in($paths)
            ->exclude(['vendor', 'node_modules'])
            ->name('*.html')
            ->files()
        ;
    }

    /**
     * 获取视图扫描目录.
     */
    protected function paths(): array
    {
        return array_merge([$this->app->themesPath()], $this->composerPaths());
    }

    /**
     * 取得应用的 composer 配置.
     */
    protected function composerPaths(): array
    {
        $path = $this->app->path().'/composer.json';
        if (!is_file($path)) {
            return [];
        }

        $config = $this->getFileContent($path);
        $paths = $config['extra']['leevel-console']['view-cache']['paths'] ?? [];
        $path = $this->app->path();

        return array_map(function (string $value) use ($path): string {
            if (!is_file($value)) {
                $value = $path.'/'.$value;
            }

            if (!is_dir($value)) {
                throw new \RuntimeException(sprintf('View dir `%s` is not exist.', $value));
            }

            return $value;
        }, $paths);
    }

    /**
     * 获取配置信息.
     *
     * @throws \JsonException
     */
    protected function getFileContent(string $path): array
    {
        return (array) json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 创建模板分析器.
     */
    protected function createParser(): Parser
    {
        return (new Parser(new Compiler()))
            ->registerCompilers()
            ->registerParsers()
        ;
    }

    /**
     * 获取 HTML 视图仓储.
     */
    protected function getHtmlView(): Html
    {
        /** @var Manager $view */
        $view = $this->app
            ->container()
            ->make('views')
        ;

        // @phpstan-ignore-next-line
        return $view->connect('html');
    }
}
