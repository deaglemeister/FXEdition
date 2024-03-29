<?php
namespace ide\forms;



use ide\editors\form\IdeTabPane;
use ide\forms\mixins\SavableFormMixin;
use ide\Ide;
use ide\IdeConfigurable;
use ide\IdeException;
use ide\Logger;
use ide\project\templates\DefaultGuiProjectTemplate;
use ide\systems\FileSystem;
use ide\systems\ProjectSystem;
use ide\systems\WatcherSystem;
use ide\utils\FileUtils;
use ide\utils\UiUtils;
use php\desktop\HotKeyManager;
use php\desktop\Robot;
use php\gui\designer\UXDesigner;
use php\gui\designer\UXDirectoryTreeValue;
use php\gui\designer\UXDirectoryTreeView;
use php\gui\designer\UXFileDirectoryTreeSource;
use php\gui\dock\UXDockNode;
use php\gui\dock\UXDockPane;
use php\gui\event\UXEvent;
use php\gui\event\UXKeyboardManager;
use php\gui\event\UXKeyEvent;
use php\gui\event\UXMouseEvent;
use php\gui\framework\AbstractForm;
use php\gui\framework\Preloader;
use php\gui\layout\UXAnchorPane;
use php\gui\layout\UXHBox;
use php\gui\layout\UXVBox;
use php\gui\UXAlert;
use php\gui\UXApplication;
use php\gui\UXButton;
use php\gui\UXForm;
use php\gui\UXImage;
use php\gui\UXImageView;
use php\gui\UXLabel;
use php\gui\UXMenu;
use php\gui\UXMenuBar;
use php\gui\UXMenuItem;
use php\gui\UXNode;
use php\gui\UXScreen;
use php\gui\UXSplitPane;
use php\gui\UXTab;
use php\gui\UXTabPane;
use php\gui\UXTextArea;
use php\gui\UXTreeView;
use php\io\File;
use php\lang\System;
use php\lib\fs;
use php\lib\str;
use script\TimerScript;
use php\time\Timer;
use std;
use action\Animation;

use php\gui\UXControl;

use php\gui\layout\UXScrollPane;

use httpclient;
use php\io\IOException;
use php\framework\Logger;
use facade\Json;
use bundle\http\HttpClient;

use ide\forms\malboro\Modals;

use ide\forms\malboro\Toasts;

use ide\forms\malboro\Updates;
use ide\forms\malboro\DiscordRPC;

/**
 * @property UXTabPane $fileTabPane
 * @property UXTabPane $projectTabs
 * @property UXVBox $properties
 * @property UXSplitPane $contentSplit
 * @property UXAnchorPane $directoryTree
 * @property UXTreeView $projectTree
 * @property UXHBox $headPane
 * @property UXHBox $headRightPane
 * @property UXVBox $contentVBox
 * @property UXAnchorPane $bottomSpoiler
 * @property UXTabPane $bottomSpoilerTabPane
 * @property UXSplitPane $splitTree
 *
 * @property UXDockPane $dockPane
 * @property UXHBox $statusPane
 */
class MainForm extends AbstractIdeForm
{
    use IdeConfigurable;
    
    /**
     * @var UXMenuBar
     */
    public $mainMenu;

    /**
     * @var UXAnchorPane
     */
    private $bottom;

    /**
     * MainForm constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->bottom = $this->bottomSpoiler;

        foreach ($this->contentVBox->children as $one) {
            if ($one instanceof UXMenuBar) {
                $this->mainMenu = $one;
                break;
            }
        }

        if (!$this->mainMenu) {
            throw new IdeException("Cannot find main menu on main form");
        }
    }

    /**
     * @param $string
     * @return null|UXMenu
     */
    public function findSubMenu($string)
    {
        /** @var UXMenu $one */
        foreach ($this->mainMenu->menus as $one) {
            if ($one->id == $string) {
                return $one;
            }
        }

        return null;
    }

    protected function init()
    {
        parent::init();

        $this->opacity = 0.01;

        Ide::get()->on('start', function () {
            $this->opacity = 1;
        });

        app()->form('NewSplashForm')->status-> text = 'Запускаем главную форму';

        $mainMenu = $this->mainMenu; // FIX!!!!! see FixSkinMenu

        $this->headRightPane->spacing = 5;

        $pane = UXTabPane::createDefaultDnDPane();

        $parent = $this->fileTabPane->parent;
        $this->fileTabPane->free();

        /** @var UXTabPane $tabPane */
        $tabPane = $pane ? $pane->children[0] : new UXTabPane();
        $tabPane->id = 'fileTabPane';
        $tabPane->tabClosingPolicy = 'ALL_TABS';

        $tabPane->classes->add('dn-file-tab-pane');


        if ($pane) {
            UXAnchorPane::setAnchor($pane, 0);
            $parent->add($pane);
        } else {
            $parent->add($tabPane);
        }

        $tree = new UXDirectoryTreeView();
        $tree->position = [0, 0];
        $tree->style = UiUtils::fontSizeStyle();
        $this->directoryTree->add($tree);

        UXAnchorPane::setAnchor($tree, 0);

        Ide::get()->bind('shutdown', function () {
            $this->ideConfig()->set("splitTree.dividerPositions", $this->splitTree->dividerPositions);
        });

        Ide::get()->bind('openProject', function () use ($tree) {
            $project = Ide::project();
            $project->getTree()->setView($tree);

            $tree->treeSource = $project->getTree()->createSource();

            $tree->root->expanded = true;
            $project->getConfig()->loadTreeState($project->getTree());

            $label = new UXLabel(fs::normalize($project->getMainProjectFile()));
            $label->paddingLeft = 10;

            $this->statusPane->children->setAll([
                $label
            ]);
        });

        Ide::get()->bind('afterCloseProject', function () use ($tree) {
            $tree->treeSource->shutdown();
            $tree->treeSource = null;

            $this->statusPane->children->clear();
        });
    }

    /**
     * @param string $id
     * @param string $text
     * @param bool $prepend
     * @return UXMenu
     * @throws IdeException
     */
    public function defineMenuGroup($id, $text, $prepend = false)
    {
        $id = str::upperFirst($id);

        $menu = null;

        foreach ($this->mainMenu->menus as $one) {
            if ($one->id == "menu$id") {
                $menu = $one;
                break;
            }
        }

        if ($menu == null) {
            $menu = new UXMenu($text);
            $menu->id = "menu$id";
    
            if ($prepend) {
                $this->mainMenu->menus->insert(0, $menu);
            } else {
                $this->mainMenu->menus->add($menu);
            }
        } else {
            $menu->text = $text;
        }

        return $menu;
    }

    /**
     * @event showing
     */
    public function doShowing()
    {
        $class = new Updates;
        $class->checkUpdates();
    }
 
    public function show()
    {
       
        parent::show();
        $this->minWidth = 1450;
        $this->minHeight = 850;
       # $class = new DiscordRPC;
       # $class->RPC();
        Ide::get()->getL10n()->translateNode($this->mainMenu);

     $ideLanguage = Ide::get()->getLanguage();

        $menu = $this->findSubMenu('menuL10n');
        $menu->items->clear();

        if ($ideLanguage) {
            $menu->graphic = Ide::get()->getImage(new UXImage($ideLanguage->getIcon()));
            $menu->text = _('lang.name.f');
        }

        foreach (Ide::get()->getLanguages() as $language) {
            $item = new UXMenuItem($language->getTitle(), Ide::get()->getImage(new UXImage($language->getIcon())));

            if ($language->getTitle() != $language->getTitleEn()) {
                $item->text .= ' (' . $language->getTitleEn() . ')';
            }

            //$item->enabled = !$ideLanguage || $language->getCode() != $ideLanguage->getCode();

            $item->on('action', function () use ($language, $item, $menu) {
                $msg = new MessageBoxForm($language->getRestartMessage(), [$language->getRestartYes(), $language->getRestartNo()]);
                $msg->makeWarning();
                $msg->showDialog();

                $menu->graphic = Ide::get()->getImage(new UXImage($language->getIcon()));
                Ide::get()->setUserConfigValue('ide.language', $language->getCode());

                if ($msg->getResultIndex() == 0) {
                    Ide::get()->restart();
                }
            });

            $menu->items->add($item);
        } 

        $screen = UXScreen::getPrimary();

        $this->showBottom(null);

        $this->width  = Ide::get()->getUserConfigValue(get_class($this) . '.width', $screen->bounds['width'] * 0.75);
        $this->height = Ide::get()->getUserConfigValue(get_class($this) . '.height', $screen->bounds['height'] * 0.75);

        if ($this->width < 300 || $this->height < 200) {
            $this->width = $screen->bounds['width'] * 0.75;
            $this->height = $screen->bounds['height'] * 0.75;
        }

        $this->centerOnScreen();

        $this->x = Ide::get()->getUserConfigValue(get_class($this) . '.x', 0);
        $this->y = Ide::get()->getUserConfigValue(get_class($this) . '.y', 0);

        if ($this->x > $screen->visualBounds['width'] - 10 || $this->y > $screen->visualBounds['height'] - 10 ||
            $this->x < -999 || $this->y < -999) {
            $this->x = $this->y = 20;
        }

        $this->maximized = Ide::get()->getUserConfigValue(get_class($this) . '.maximized', true);

        $this->observer('maximized')->addListener(function ($old, $new) {
            Ide::get()->setUserConfigValue(get_class($this) . '.maximized', $new);
        });

        foreach (['width', 'height', 'x', 'y'] as $prop) {
            $this->observer($prop)->addListener(function ($old, $new) use ($prop) {
                if ($this->iconified) {
                    return;
                }

                if (!$this->maximized) {
                    Ide::get()->setUserConfigValue(get_class($this) . '.' . $prop, $new);
                }
            });
        }

        uiLater(function () {
            if ($this->ideConfig()->has('splitTree.dividerPositions')) {
                $this->splitTree->dividerPositions = $this->ideConfig()->getArray('splitTree.dividerPositions', [0.3]);
            }
        });
    }


    /**
     * @event close
     *
     * @param UXEvent $e
     *
     * @throws \Exception
     * @throws \php\io\IOException
     */
    public function doClose(UXEvent $e = null)
    {
        $e->consume();
        $project = Ide::get()->getOpenedProject();
        $modal = [
            'fitToWidth' => true, # Во всю длину
            'fitToHeight' => true, # Во всю ширину
            'blur' => app()->form('MainForm')->flowPane, # Объект для размытия
            'title' => 'Вы действительно хотите выйти из среды?', # Заголовок окна
            'message' => 'Не забудьте сохранить ваш проект.', # Сообщение
            'color_overlay' => "#ff5252",
            'close_overlay' => false, # Закрывать при клике мыши на overlay
            'buttons' => [['text' => 'Да, выйти', 'style' => 'button-red'], ['text' => 'Отмена', 'style' => 'button-accent', 'close' => true]]
            ];
        $modalClass = new Modals;
        $MainFormZ = app()->form('MainForm');
        $modalClass->modal_dialog(app()->form('MainForm'), $modal, function($e) use ($MainFormZ) {
            if ($e == 'Да, выйти') {
                Exit();
            }
        });
    }
    /**
     * @event keyDown-F5 
     */
    function doKeyDownF5(UXKeyEvent $e = null)
    {    
        $e->consume();
        $project = Ide::get()->getOpenedProject();
        # Параметры модального окна
        $modal = [
            'fitToWidth' => true, # Во всю длину
            'fitToHeight' => true, # Во всю ширину
            'blur' => $this->flowPane, # Объект для размытия
            'title' => 'Вы действительно хотите перезагрузить среду?', # Заголовок окна
            'message' => 'Тогда не забудьте, сохранить ваш проект.', # Сообщение
            'close_overlay' => true, # Закрывать при клике мыши на overlay
            'buttons' => [['text' => 'Перезагрузить среду', 'style' => 'button-red'], ['text' => 'Отмена', 'style' => 'button-accent', 'close' => true]]
            ];
        # Отображаем окно      
        $modalClass = new Modals;
        $MainFormZ = app()->form('MainForm');
        $modalClass->modal_dialog(app()->form('MainForm'), $modal, function($e) use ($MainFormZ) {
            if ($e == 'Перезагрузить среду') {
                Execute("DevelNext.exe"); // Даем команду на запуск приложения
                Exit(); // Закрываем приложение
            }
        });

    }



    /**
     * @event keyDown-Ctrl+W 
     */
    function doKeyDownCtrlW(UXKeyEvent $e = null)
    {    
        $e->consume();
        
    }


        /**
     * @event keyDown-F11 
     */
    function doKeyDownF11(UXKeyEvent $e = null)
    {    
        $e->consume();
        $project = Ide::get()->getOpenedProject();
        # Параметры модального окна
        $modal = [
            'fitToWidth' => true, # Во всю длину
            'fitToHeight' => true, # Во всю ширину
            'blur' => $this->flowPane, # Объект для размытия
            'title' => 'Вызвать сборщик мусора?', # Заголовок окна
            'message' => 'Ваша память будет почищена.', # Сообщение
            'close_overlay' => true, # Закрывать при клике мыши на overlay
            'buttons' => [['text' => 'Вызвать', 'style' => 'button-red'], ['text' => 'Отмена', 'style' => 'button-accent', 'close' => true]]
            ];
        # Отображаем окно      
        $modalClass = new Modals;
        $MainFormZ = app()->form('MainForm');
        $modalClass->modal_dialog(app()->form('MainForm'), $modal, function($e) use ($MainFormZ) {
            if ($e == 'Вызвать') {
                $this->showPreloader('Выдвигаеюсь..');
                new Thread(function() {
                    System::gc();
                    uiLater(function() {
                        $this->hidePreloader();
                        $class = new Toasts;
                        $class->showToast("Сборщик мусора", "Текущая память была освобождена", "#FF4F44");
                    });
                })->start();
                
                
                
            }
        });

    }

    
        /**
     * @event keyDown-F12 
     */
    function doKeyDownF12(UXKeyEvent $e = null)
    {    
        $class = new Updates;
        $class->checkUpdates();
    }

    
    /**
     * @return UXHBox
     */
    public function getHeadPane()
    {
        return $this->headPane;
    }

    /**
     * @return UXHBox
     */
    public function getHeadRightPane()
    {
        return $this->headRightPane;
    }

    /**
     * @return UXTreeView
     */
    public function getProjectTree()
    {
        return $this->projectTree;
    }

    public function hideBottom()
    {
        $this->showBottom(null);
    }

    public function showBottom(UXNode $content = null)
    {
        if ($content) {
            if (!$this->contentSplit->items->has($this->bottom)) {
                $this->contentSplit->items->add($this->bottom);
            }

            $this->bottom->children->clear();

            $height = $this->layout->height;

            $content->height = (int) Ide::get()->getUserConfigValue('mainForm.consoleHeight', 350);

            $content->observer('height')->addListener(function ($old, $new) use ($content) {
                if (!$content->isFree()) {
                    Ide::get()->setUserConfigValue('mainForm.consoleHeight', $new);
                }
            });

            UXAnchorPane::setAnchor($content, 0);

            $this->bottom->add($content);

            $percent = (($content->height + 3) * 100 / $height) / 100;

            $this->contentSplit->dividerPositions = [1 - $percent, $percent];
        } else {
            $this->contentSplit->items->remove($this->bottom);
        }
    }
}