<?php

namespace Imanghafoori\LaravelMicroscope;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Imanghafoori\LaravelMicroscope\Analyzers\FilePath;
use Imanghafoori\LaravelMicroscope\Analyzers\GetClassProperties;
use Imanghafoori\LaravelMicroscope\Analyzers\NamespaceCorrector;
use Imanghafoori\LaravelMicroscope\Analyzers\ParseUseStatement;
use Imanghafoori\LaravelMicroscope\Contracts\FileCheckContract as FileCheckContractAlias;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;

class CheckClasses
{
    /**
     * Get all of the listeners and their corresponding events.
     *
     * @param  iterable  $files
     * @param  FileCheckContractAlias  $fileCheckContract
     *
     * @return void
     */
    public static function checkImports($files, FileCheckContractAlias $fileCheckContract)
    {
        foreach ($files as $classFilePath) {
            $absFilePath = $classFilePath->getRealPath();

            $tokens = token_get_all(file_get_contents($absFilePath));

            // If file is empty or does not begin with <?php
            if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
                continue;
            }
            [
                $currentNamespace,
                $class,
                $type,
                $parent,
                $interfaces
            ] = GetClassProperties::readClassDefinition($tokens);

            // It means that, there is no class/trait definition found in the file.
            if (! $class) {
                continue;
            }

            event('laravel_microscope.checking_file', [$absFilePath]);
            // @todo better to do it an event listener.
            $fileCheckContract->onFileTap($classFilePath);

            $tokens = token_get_all(file_get_contents($absFilePath));

            self::checkAtSignStrings($tokens, $absFilePath);

            $nonImportedClasses = ParseUseStatement::findClassReferences($tokens, $absFilePath);

            foreach ($nonImportedClasses as $nonImportedClass) {
                $v = trim($nonImportedClass['class'], '\\');
                if (! class_exists($v) && ! trait_exists($v) && ! interface_exists($v) && ! function_exists($v)) {
                    app(ErrorPrinter::class)->wrongUsedClassError($absFilePath, $nonImportedClass['class'], $nonImportedClass['line']);
                }
            }

//          $classPath = self::relativePath($basePath, $absFilePath);
//          $correctNamespace = NamespaceCorrector::calculateCorrectNamespace($classPath, $composerPath, $composerNamespace);

            $namespacedClassName = self::fullNamespace($currentNamespace, $class);

            $imports = ParseUseStatement::getUseStatementsByPath($namespacedClassName, $absFilePath);
            self::checkImportedClassesExist($imports, $absFilePath);

            if ($currentNamespace) {
                if (is_subclass_of($currentNamespace.'\\'.$class, Model::class)) {
                    ModelRelations::checkModelRelations($tokens, $currentNamespace, $class, $absFilePath);
                }
            } else {
                // @todo show skipped file...
            }
        }
    }

    /**
     * Get all of the listeners and their corresponding events.
     *
     * @param  iterable  $paths
     * @param $composerPath
     * @param $composerNamespace
     * @param  FileCheckContractAlias  $fileCheckContract
     *
     * @return void
     */
    public static function forNamespace($paths, $composerPath, $composerNamespace, FileCheckContractAlias $fileCheckContract)
    {
        foreach ($paths as $classFilePath) {
            $absFilePath = $classFilePath->getRealPath();

            // exclude blade files
            if (Str::endsWith($absFilePath, ['.blade.php'])) {
                continue;
            }

            // exclude migration directories
            if (Str::startsWith($absFilePath, self::migrationPaths())) {
                continue;
            }

            if (! self::hasOpeningTag($absFilePath)) {
                continue;
            }

            if ($fileCheckContract) {
                $fileCheckContract->onFileTap($classFilePath);
            }

            [
                $currentNamespace,
                $class,
                $type,
                $parent
            ] = GetClassProperties::fromFilePath($absFilePath);

            // skip if there is no class/trait/interface definition found.
            // for example a route file or a config file.
            if (! $class || $parent == 'Migration') {
                continue;
            }

            $relativePath = FilePath::getRelativePath($absFilePath);
            $correctNamespace = NamespaceCorrector::calculateCorrectNamespace($relativePath, $composerPath, $composerNamespace);
            if ($currentNamespace !== $correctNamespace) {
                self::doNamespaceCorrection($correctNamespace, $relativePath, $currentNamespace, $absFilePath);
            }
        }
    }

    public static function hasOpeningTag($file)
    {
        $fp = fopen($file, 'r');

        if (feof($fp)) {
            return false;
        }

        $buffer = fread($fp, 20);
        fclose($fp);

        return Str::startsWith($buffer, '<?php');
    }

    /**
     * Calculate the namespace\className from absolute file path.
     *
     * @param  string  $filePath
     * @param  string  $basePath
     *
     * @param $path
     * @param $rootNamespace
     *
     * @return string
     */
    protected static function calculateClassFromFile($filePath, $basePath, $path, $rootNamespace)
    {
        $class = trim(Str::replaceFirst($basePath, '', $filePath), DIRECTORY_SEPARATOR);

        // remove .php from class path
        $withoutDotPhp = Str::replaceLast('.php', '', $class);
        // ensure backslash on windows
        $allBackSlash = str_replace(DIRECTORY_SEPARATOR, '\\', $withoutDotPhp);

        // replaces the base folder name with corresponding namespace
        return str_replace(rtrim($path, '/').'\\', $rootNamespace, $allBackSlash);
    }

    private static function checkImportedClassesExist($imports, $absPath)
    {
        foreach ($imports as $i => $import) {
            if (self::exists($import[0])) {
                app(ErrorPrinter::class)->wrongImport($absPath, $import[0], $import[1]);
            }
        }
    }

    private static function exists($imp)
    {
        return ! class_exists($imp) && ! interface_exists($imp) && ! trait_exists($imp);
    }

    protected static function doNamespaceCorrection($correctNamespace, $classPath, $currentNamespace, $absFilePath)
    {
        event('laravel_microscope.namespace_fixing', get_defined_vars());
        NamespaceCorrector::fix($absFilePath, $currentNamespace, $correctNamespace);
        event('laravel_microscope.namespace_fixed', get_defined_vars());

        // maybe an event listener
        app(ErrorPrinter::class)->badNamespace($classPath, $correctNamespace, $currentNamespace);
    }

    private static function migrationPaths()
    {
        // normalize the migration paths
        $migrationDirs = [];

        foreach (app('migrator')->paths() as $path) {
            $migrationDirs[] = FilePath::normalize($path);
        }

        return $migrationDirs;
    }

    /**
     * @param $currentNamespace
     * @param $class
     *
     * @return string
     */
    protected static function fullNamespace($currentNamespace, $class)
    {
        if ($currentNamespace) {
            $namespacedClassName = $currentNamespace.'\\'.$class;
        } else {
            $namespacedClassName = $class;
        }

        return $namespacedClassName;
    }

    public static function checkAtSignStrings($tokens, $absFilePath, $onlyAbsClassPath = false)
    {
        foreach ($tokens as $token) {
            if ($token[0] != T_CONSTANT_ENCAPSED_STRING || substr_count($token[1], '@') != 1) {
                continue;
            }
            $trimmed = trim($token[1], '\'\"');

            if ($onlyAbsClassPath && $trimmed[0] !== '\\') {
                continue;
            }

            [$class, $method] = explode('@', $trimmed);

            if (substr_count($class, '\\') <= 0) {
                continue;
            }

            if (! class_exists($class)) {
                app(ErrorPrinter::class)->wrongUsedClassError($absFilePath, $token[1], $token[2]);
            } else {
                if (! method_exists($class, $method)) {
                    app(ErrorPrinter::class)->wrongMethodError($absFilePath, $trimmed, $token[2]);
                }
            }
        }
    }
}
