<?php

use DeepL\Translator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\VarExporter\VarExporter;

define('_TRANS_PATTERN_', '(.*[^\\\\])');

class PrestashopModuleTranslaterService
{
    private $translator;
    private $destinationIsoCountries;
    private $filesystem;
    private $sourceIsoCountry;
    private $modulePath = '../../';

    const MODULE_PATH = '../../translations';

    /**
     * @throws \DeepL\DeepLException
     * @throws Exception
     */
    public function __construct($real_path = null)
    {

        if ($real_path !== null) {
            $this->modulePath = $real_path;
        }

        if (!is_dir($this->modulePath)) {
            throw new \Exception('The module path does not exist');
        }

        $envFilePath = self::MODULE_PATH . DIRECTORY_SEPARATOR . '.env';
        if (!file_exists($envFilePath)) {
            $envFilePath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        }
        if (!file_exists($envFilePath)) {
            throw new \Exception('The .env file does not exist');
        }

        (new Dotenv())->load($envFilePath);

        $apiKey = $_ENV['DEEPL_API_KEY'];
        if (empty($apiKey)) {
            throw new \Exception('The DEEPL_API_KEY is not defined in the .env file');
        }

        $sourceIsoCountry = $_ENV['DEEPL_ISO_LANG_SRC'];
        if (empty($sourceIsoCountry)) {
            $sourceIsoCountry = 'en';
        }

        $destinationIsoCountries = explode(',', $_ENV['DEEPL_ISO_LANG_DEST']);
        if (empty($destinationIsoCountries)) {
            throw new \Exception('The DESTINATION_ISO_COUNTRIES is not defined in the .env file');
        }

        $this->translator = new Translator($apiKey);
        $this->destinationIsoCountries = $destinationIsoCountries;
        $this->filesystem = new Filesystem();
        $this->sourceIsoCountry = $sourceIsoCountry;

        $this->exec();
    }

    private function exec()
    {

        $arrayToTranslate = $this->getArrayToTranslate();
        foreach ($this->destinationIsoCountries as $destinationIsoCountry) {
            $translatedArray = [];
            $filePath = $this->getTranslationPath() . $destinationIsoCountry . '.php';

            if ($this->filesystem->exists($filePath)) {
                include $filePath;
                foreach ($_MODULE as $key => $value) {
                    if (isset($arrayToTranslate[$key])) {
                        $translatedArray[$key] = $value;
                    }
                }
            }

            $countTranslations = 0;

            foreach ($arrayToTranslate as $key => $text) {
                if (!isset($translatedArray[$key])) {
                    try {
                        $translation = $this->translator->translateText(
                            $text,
                            $this->sourceIsoCountry,
                            $destinationIsoCountry
                        );
                        $translatedArray[$key] = $translation->text;
                        $countTranslations++;
                    } catch (\DeepL\DeepLException $e) {
                        echo "Erreur DeepL : " . $e->getMessage();
                    } catch (IOExceptionInterface $e) {
                        echo "Une erreur s'est produite lors de l'écriture du fichier : " . $e->getMessage();
                    } catch (Exception $e) {
                        echo "Erreur : " . $e->getMessage();
                    }
                }
            }

            try {
                $phpCode = '<?php' . PHP_EOL . PHP_EOL;
                $phpCode .= 'global $_MODULE;' . PHP_EOL;
                $phpCode .= '$_MODULE = ' . VarExporter::export($translatedArray) . ';';
                $this->filesystem->dumpFile($filePath, $phpCode);

            } catch (IOExceptionInterface $e) {
                echo "Une erreur s'est produite lors de l'écriture du fichier : " . $e->getMessage();
            }

            echo "Translations for $destinationIsoCountry: $countTranslations\n";

        }
    }

    private function getArrayToTranslate(): array
    {
        //Recherche de tous les fichiers .php et .tpl dans le dossier du module recursivement
        $files = $this->getFiles($this->modulePath);
        $module_name = $this->getModuleName();
        $modules_translations = [];

        foreach ($files as $file) {
            $template_name = substr(basename($file), 0, -4);
            $content = file_get_contents($file);
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $matches =  $this->userParseFile($content, $extension);
            foreach ($matches as $key) {
                $md5_key = md5($key);
                $default_key = '<{' . strtolower($module_name) . '}prestashop>' . strtolower($template_name) . '_' . $md5_key;
                $modules_translations[$default_key] = html_entity_decode($key, ENT_COMPAT, 'UTF-8');;
            }
        }

        return $modules_translations;
    }

    private function userParseFile($content, $extension): array
    {
        if ($extension == 'php') {
            $regex = '/->l\(\s*(\')' . _TRANS_PATTERN_ . '\'(\s*,\s*?\'(.+)\')?(\s*,\s*?(.+))?\s*\)/Ums';
        } else {
            // In tpl file look for something that should contain mod='module_name' according to the documentation
            $regex = '/\{l\s*s=([\'\"])' . _TRANS_PATTERN_ . '\1.*\s+mod=\'' . $this->getModuleName() . '\'.*\}/U';
        }

        $strings = $matches = [];

        $n = preg_match_all($regex, $content, $matches);


        for ($i = 0; $i < $n; ++$i) {
            $quote = $matches[1][$i];
            $string = $matches[2][$i];

            if ($quote === '"') {
                // Escape single quotes because the core will do it when looking for the translation of this string
                $string = str_replace('\'', '\\\'', $string);
                // Unescape double quotes
                $string = preg_replace('/\\\\+"/', '"', $string);
            }

            $strings[] = $string;
        }

        return array_unique($strings);
    }

    private function getFiles($modulePath)
    {
        $files = [];
        $dir = new \DirectoryIterator($modulePath);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isFile() && !in_array($fileinfo->getExtension(), ['tpl', 'php'])) {
                continue;
            }
            if ($fileinfo->isDir() && in_array($fileinfo->getFilename(), ['translations', 'vendor'])) {
                continue;
            }
            if ($fileinfo->isFile()) {
                $files[] = $fileinfo->getPathname();
            } elseif (!$fileinfo->isDot() && $fileinfo->isDir()) {
                $files = array_merge($files, $this->getFiles($fileinfo->getPathname()));
            }
        }
        return $files;
    }

    private function getModuleName(): string
    {
        return basename($this->modulePath);
    }

    private function getTranslationPath(): string
    {
        return $this->modulePath.DIRECTORY_SEPARATOR.'translations'.DIRECTORY_SEPARATOR;
    }
}
