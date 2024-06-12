<?php

namespace ide\project;

use platform\facades\PluginManager;
use platform\plugins\traits\FileTypes;


use ide\commands\tree\TreeCopyPathCommand;
use ide\commands\tree\TreeDeleteFileCommand;
use ide\commands\tree\TreeEditFileCommand;
use ide\commands\tree\TreeEditInWindowFileCommand;
use ide\commands\tree\TreeRenameFileCommand;
use ide\commands\tree\TreeScriptHelperMenuCommand;
use ide\commands\tree\TreeShowInExplorerCommand;
use ide\editors\menu\ContextMenu;
use ide\forms\MessageBoxForm;
use ide\Ide;
use ide\systems\FileSystem;
use ide\utils\FileUtils;
use php\gui\designer\UXDirectoryTreeValue;
use php\gui\designer\UXDirectoryTreeView;
use php\gui\designer\UXFileDirectoryTreeSource;
use php\gui\event\UXDragEvent;
use php\gui\event\UXMouseEvent;
use php\gui\UXDesktop;
use php\gui\UXImage;
use php\gui\UXImageView;
use php\io\File;
use php\lang\Process;
use php\lib\fs;

use platform\facades\Toaster;
use platform\toaster\ToasterMessage;
use php\gui\UXImage;
use php\lib\str;


/**
 * Class ProjectTree
 * @package ide\project
 */
class ProjectTree
{
    /**
     * @var Project
     */
    protected $project;

    /**
     * @var UXDirectoryTreeView
     */
    protected $tree;

    /**
     * @var array
     */
    protected $ignoreExts = [];

    /**
     * @var array
     */
    protected $ignorePaths = ['.dn' => 1];

    /**
     * @var callable[]
     */
    protected $ignoreFilters = [];

    /**
     * @var callable[]
     */
    protected $openHandlers = [];

    /**
     * @var callable[]
     */
    protected $valueCreators = [];

    /**
     * @var ContextMenu
     */
    protected $contextMenu;

    /**
     * ProjectTree constructor.
     * @param Project $project
     */
    public function __construct(Project $project)
    {
        $this->project = $project;
        $this->contextMenu = new ContextMenu();

        $this->contextMenu->addGroup('new',  _('Добавить'));
        $this->contextMenu->addSeparator();
        $this->contextMenu->add(new TreeCopyPathCommand($this, true)); //Скопировать путь
        $this->contextMenu->add(new TreeCopyPathCommand($this));
        $this->contextMenu->add(new TreeRenameFileCommand($this));
        $this->contextMenu->add(new TreeDeleteFileCommand($this));
        $this->contextMenu->addSeparator();
        #$this->contextMenu->add(new TreeEditFileCommand($this)); //Редактировать
        $this->contextMenu->add(new TreeShowInExplorerCommand($this)); //Показать в папке
        $this->contextMenu->add(new TreeEditInWindowFileCommand($this)); //редактировать в новом окне
        $this->contextMenu->addSeparator();
        $this->contextMenu->add(new TreeScriptHelperMenuCommand($this)); //Сгенирировать скрипт

    }

    /**
     * @return ContextMenu
     */
    public function getContextMenu()
    {
        return $this->contextMenu;
    }

    /**
     * @return string
     */
    public function getSelectedPath()
    {
        if ($this->tree) {
            return $this->tree->selectedItems ? $this->tree->selectedItems[0]->value->path : null;
        }

        return null;
    }

    /**
     * @return bool
     */
    public function hasSelectedPath()
    {
        return $this->tree && $this->tree->selectedItems;
    }

    /**
     * @return ProjectFile|null|File
     */
    public function getSelectedFullPath()
    {
        $path = $this->getSelectedPath();

        if ($this->hasSelectedPath()) {
            return $this->project->getFile($path);
        }

        return null;
    }

    public function expandSelected()
    {
        if ($this->tree && $this->tree->selectedItems) {
            $this->tree->selectedItems[0]->expanded = true;
        }
    }

    /**
     * @param UXDirectoryTreeView $treeView
     */
    public function setView(UXDirectoryTreeView $treeView)
    {
        $this->tree = $treeView;

        $this->contextMenu->linkTo($treeView);

        $treeView->on('dragOver', function (UXDragEvent $e) {
            if ($e->dragboard->files) {
                $e->acceptTransferModes(['MOVE', 'COPY']);
                $e->consume();
            }
        });

        $treeView->on('dragDrop', function (UXDragEvent $e) use ($treeView) {
            if ($e->dragboard->files && $treeView->focusedItem) {

                $item = $treeView->focusedItem;

                if ($item->value) {
                    $destFile = $this->project->getFile($item->value->path);

                    if ($destFile->isFile()) {
                        $destFile = $destFile->getParentFile();
                    }

                    foreach ($e->dragboard->files as $file) {
                        if (FileUtils::startsName($destFile->getPath(), $file)) {
                            continue;
                        }

                        $copyFile = $destFile->getPath() . "/" . fs::name($file);

                        if (FileUtils::equalNames($file, $copyFile)) {
                            continue;
                        }

                        if ($file->isFile()) {
                            FileUtils::copyFile($file, $copyFile);
                        } else {
                            fs::makeDir($copyFile);
                            FileUtils::copyDirectory($file, $copyFile);
                        }

                        // in root dir of project
                        if (str::startsWith(FileUtils::hashName($file), FileUtils::hashName($this->project->getRootDir()))) {
                            if ($file->isDirectory()) {
                                FileUtils::deleteDirectory($file);
                            } else {
                                fs::delete($file);
                            }
                        }
                    }

                    $e->consume();
                }
            }
        });

        $treeView->on('click', function (UXMouseEvent $e) use ($treeView) {
            if ($e->clickCount > 1) {
                $selectedPath = $this->getSelectedPath();

                if ($selectedPath) {
                    $file = $this->project->getFile($selectedPath);

                    if ($file->isFile()) {
                        foreach ($this->openHandlers as $handler) {
                            if ($handler($file)) {
                                return;
                            }
                        }

                        if (FileSystem::isOpenedAndSelected($file)) {
                            return;
                        }

                        $editor = FileSystem::open($file);

                        if (!$editor) {
                            switch (fs::ext($file)) {
                                case 'png':
                                case 'jpg':
                                case 'jpeg':
                                case 'bmp':
                                case 'gif':
                                case 'ico':
                                case 'wav':
                                case 'ogg':
                                case 'wave':
                                case 'mp3':
                                case 'aif':
                                case 'aiff':
                                case 'zip':
                                case 'rar':
                                case '7z':
                                case 'mp4':
                                case 'flv':
                                    open($file);
                                    break;

                                case 'ini':
                                case 'conf':
                                case 'txt':
                                case 'log':
                                case 'js':
                                case 'json':
                                case 'html':
                                case '':
                                    $desktop = new UXDesktop();
                                    $desktop->edit($file);
                                    break;

                                case 'exe':                              
                                        $tm = new ToasterMessage();
                                        $iconImage = new UXImage('res://resources/expui/icons/fileTypes/info.png');
                                        $tm
                                            ->setIcon($iconImage)
                                            ->setTitle('Менеджер по работе с открытием')
                                            ->setDescription(_('Запустить исполняемый файл ' . fs::name($file) . '?'))
                                            ->setLink('Открыть исполняемый файл', function ($file) {
                                                execute($file);
                                            })
                                            ->setClosable();
                                        Toaster::show($tm);
                                        
                                    break;

                                case 'bat':
                                    if (Ide::get()->isWindows() && MessageBoxForm::confirm("Запустить исполняемый файл " . fs::name($file) . '?')) {
                                        $process = new Process(['cmd', '/c', $file], fs::parent($file), (array)$_ENV);
                                        $process->start();
                                        return;
                                    }

                                    if (!Ide::get()->isWindows()) {
                                        $desktop = new UXDesktop();
                                        $desktop->edit($file);
                                        return;
                                    }

                                    break;

                                default:
                                        $tm = new ToasterMessage();
                                        $iconImage = new UXImage('res://resources/expui/icons/fileTypes/error.png');
                                        $tm
                                            ->setIcon($iconImage)
                                            ->setTitle('Менеджер по работе с открытием')
                                            ->setDescription(_('Файл невозможно открыть, открыть его через системный редактор?'))
                                            ->setLink('Да, открыть файл', function ($file) {
                                                $desktop = new UXDesktop();
                                                $desktop->open($file);
                                            })
                                            ->setClosable();
                                        Toaster::show($tm);
                                    
                            }
                        }
                    }
                }
            }
        }, __CLASS__);
    }

    public function getExpandedPaths()
    {
        return $this->tree ? $this->tree->expandedPaths : [];
    }

    /**
     * @param array $exts
     */
    public function addIgnoreExtensions(array $exts)
    {
        foreach ($exts as $ext) {
            $this->ignoreExts[$ext] = 1;
        }
    }

    public function addIgnorePaths(array $paths)
    {
        foreach ($paths as $path) {
            $this->ignorePaths[$path] = 1;
        }
    }

    public function addIgnoreFilter(callable $callback)
    {
        $this->ignoreFilters[] = $callback;
    }

    public function addValueCreator(callable $callback)
    {
        $this->valueCreators[] = $callback;
    }

    public function addOpenHandler(callable $callback)
    {
        $this->openHandlers[] = $callback;
    }

    /**
     * @return UXFileDirectoryTreeSource
     */
    public function createSource()
    {
        $source = new UXFileDirectoryTreeSource($this->project->getRootDir());
        $source->showHidden = true;

        $source->addFileFilter(function (File $file) use ($source) {
            return $this->fileFilter($file, $source);
        });

        $ide = Ide::get();

        foreach ($this->valueCreators as $creator) {
            $source->addValueCreator($creator);
        }

        $source->addValueCreator(function ($path, File $file) use ($ide) {
            return $this->createValueForFile($path, $file, $ide);
        });

        return $source;
    }

    private function fileFilter(File $file, $source)
    {
        $ext = fs::ext($file);

        if ($this->ignoreExts[$ext]) {
            return false;
        }

        if ($this->ignorePaths) {
            $path = FileUtils::relativePath($source->getDirectory(), $file);

            if ($this->ignorePaths[$path]) {
                return false;
            }
        }

        if ($this->ignoreFilters) {
            foreach ($this->ignoreFilters as $filter) {
                if ($filter($file)) {
                    return false;
                }
            }
        }

        // Проверка содержимого PHP-файлов на наличие слова "interface"
        if ($ext === 'php') {
            $this->processPhpFile($file);
        }

        return true;
    }

    private function processPhpFile(File $file)
    {
        $content = file_get_contents($file->getPath());
        if (strpos($content, 'interface') !== false) {
            return true;
        } else {
            return false;
        }
    }

    private function createValueForFile($path, File $file, $ide)
    {
        if ($file->isDirectory()) {
            $folderNames = explode("\\", $file->getPath());
            $folderName = array_pop($folderNames);

            switch ($folderName) {
                case "src_generated":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/excludeRoot_dark.png')), null, $file->isDirectory());
                case "src":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/sourceRoot_dark.png')), null, $file->isDirectory());
                case "resources":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/resourcesRoot_dark.png')), null, $file->isDirectory());
                case "app":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/excludeRoot_dark.png')), null, $file->isDirectory());
                case "img":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/generatedSource_dark.png')), null, $file->isDirectory());
                case "vendor":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/flattenModules_dark.png')), null, $file->isDirectory());
            }

            $folderName = array_pop($folderNames);

            if ($folderName == "vendor") {
                return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/module_dark.png')), null, $file->isDirectory());
            }

            return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/folder_dark.png')), null, $file->isDirectory());
        } else {

            $extFile = fs::ext($file->getName());

            switch ($extFile) {
                case "php":
                    if ($this->processPhpFile($file)) {
                        return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/interface_dark.png')), null, $file->isDirectory());
                    } else {
                        return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/class_dark.png')), null, $file->isDirectory());
                    }
                case "gitignore":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/gitignore.png')), null, $file->isDirectory());
                case "xml":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/xml_dark.png')), null, $file->isDirectory());
                case "js":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/javaScript_dark.png')), null, $file->isDirectory());
                case "json":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/json_dark.png')), null, $file->isDirectory());
                case "ini":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/config_dark.png')), null, $file->isDirectory());
                case "csv":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/csv_dark.png')), null, $file->isDirectory());
                case "java":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/java_dark.png')), null, $file->isDirectory());
                case "ttf":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/font_dark.png')), null, $file->isDirectory());
                case "sql":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/sql_dark.png')), null, $file->isDirectory());
                case "yaml":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/yaml_dark.png')), null, $file->isDirectory());
                case "css":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/xhtml_dark.png')), null, $file->isDirectory());
                case "rar":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/archive_dark.png')), null, $file->isDirectory());
                case "zip":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/archive_dark.png')), null, $file->isDirectory());
                case "png":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/image_dark.png')), null, $file->isDirectory());
                case "jpeg":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/image_dark.png')), null, $file->isDirectory());
                case "html":
                        return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/html_dark.png')), null, $file->isDirectory());
                case "xhtml":
                            return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/xhtml_dark.png')), null, $file->isDirectory());
                case "dnproject":
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/applicationRemote_dark.png')), null, $file->isDirectory());
            }

            $extFile = array_pop($extFile);

            return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView(new UXImage('res://resources/expui/icons/fileTypes/anyType_dark.png')), null, $file->isDirectory());
        }




        foreach (PluginManager::forTrait(FileTypes::class) as $plugin) {
            foreach ($plugin->getFileTypes() as $fileType) {
                if ($fileType->validate($file)) {
                    return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), new UXImageView($fileType->getIcon()), null, $file->isDirectory());
                }
            }
        }

        $format = $ide->getFormat($file);

        if ($format) {
            return new UXDirectoryTreeValue($path, fs::name($path), fs::name($path), $ide->getImage($format->getIcon()), null, $file->isDirectory());
        }
    }

    public function setExpandedPaths(array $paths)
    {
        $this->tree->expandedPaths = $paths;
    }
}
