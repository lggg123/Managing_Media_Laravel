<?php

namespace TalvBansal\MediaManager\Services;

use Carbon\Carbon;
use Dflydev\ApacheMimeTypes\PhpRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use TalvBansal\MediaManager\Contracts\FileMoverInterface;
use TalvBansal\MediaManager\Contracts\FileUploaderInterface;
use TalvBansal\MediaManager\Contracts\UploadedFilesInterface;

/**
 * Class MediaManager.
 */
class MediaManager implements FileUploaderInterface, FileMoverInterface
{
    /**
     * @var FilesystemAdapter
     */
    protected $disk;

    /**
     * @var PhpRepository
     */
    protected $mimeDetect;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * Name of the disk to upload to.
     *
     * @var string
     */
    private $diskName;

    /**
     * UploadsManager constructor.
     *
     * @param PhpRepository $mimeDetect
     */
    public function __construct(PhpRepository $mimeDetect)
    {
        $this->diskName = config('media-manager.disk');
        $this->disk = Storage::disk($this->diskName);
        $this->mimeDetect = $mimeDetect;
    }

    /**
     * Fetch any errors generated by the class when operations have been performed.
     *
     * @return array
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Return files and directories within a folder.
     *
     * @param string $folder
     *
     * @return array of [
     *               'folder' => 'path to current folder',
     *               'folderName' => 'name of just current folder',
     *               'breadCrumbs' => breadcrumb array of [ $path => $foldername ],
     *               'subfolders' => array of [ $path => $foldername] of each subfolder,
     *               'files' => array of file details on each file in folder,
     *               'itemsCount' => a combined count of the files and folders within the current folder
     *               ]
     */
    public function folderInfo($folder = '/')
    {
        $folder = $this->cleanFolder($folder);
        $breadCrumbs = $this->breadcrumbs($folder);
        $folderName = $breadCrumbs->pop();

        // Get the names of the sub folders within this folder
        $subFolders = collect($this->disk->directories($folder))->reduce(function ($subFolders, $subFolder) {
            if (!$this->isItemHidden($subFolder)) {
                $subFolders[] = $this->folderDetails($subFolder);
            }

            return $subFolders;
        }, collect([]));

        // Get all files within this folder
        $files = collect($this->disk->files($folder))->reduce(function ($files, $path) {
            if (!$this->isItemHidden($path)) {
                $files[] = $this->fileDetails($path);
            }

            return $files;
        }, collect([]));

        $itemsCount = $subFolders->count() + $files->count();

        return compact('folder', 'folderName', 'breadCrumbs', 'subFolders', 'files', 'itemsCount');
    }

    /**
     * Sanitize the folder name.
     *
     * @param $folder
     *
     * @return string
     */
    protected function cleanFolder($folder)
    {
        return DIRECTORY_SEPARATOR.trim(str_replace('..', '', $folder), DIRECTORY_SEPARATOR);
    }

    /**
     * Return breadcrumbs to current folder.
     *
     * @param $folder
     *
     * @return Collection
     */
    protected function breadcrumbs($folder)
    {
        $folder = trim($folder, '/');
        $folders = collect(explode('/', $folder));
        $path = '';

        return $folders->reduce(function ($crumbs, $folder) use ($path) {
            $path .= '/'.$folder;
            $crumbs[$path] = $folder;

            return $crumbs;
        }, collect())->prepend('Root', '/');
    }

    /**
     * Return an array of folder details for a given folder.
     *
     * @param $path
     *
     * @return array
     */
    protected function folderDetails($path)
    {
        $path = '/'.ltrim($path, '/');

        return [
            'name'         => basename($path),
            'mimeType'     => 'folder',
            'fullPath'     => $path,
            'modified'     => $this->fileModified($path),
        ];
    }

    /**
     * Return an array of file details for a given file.
     *
     * @param $path
     *
     * @return array
     */
    protected function fileDetails($path)
    {
        $path = '/'.ltrim($path, '/');

        return [
            'name'         => basename($path),
            'fullPath'     => $path,
            'webPath'      => $this->fileWebpath($path),
            'mimeType'     => $this->fileMimeType($path),
            'size'         => $this->fileSize($path),
            'modified'     => $this->fileModified($path),
            'relativePath' => $this->fileRelativePath($path),
        ];
    }

    /**
     * Return the mime type.
     *
     * @param $path
     *
     * @return string
     */
    public function fileMimeType($path)
    {
        $type = $this->mimeDetect->findType(pathinfo($path, PATHINFO_EXTENSION));
        if (!empty($type)) {
            return $type;
        }

        return 'unknown/type';
    }

    /**
     * Return the file size.
     *
     * @param $path
     *
     * @return int
     */
    public function fileSize($path)
    {
        return $this->disk->size($path);
    }

    /**
     * Return the last modified time. If a timestamp can not be found fall back
     * to today's date and time...
     *
     * @param $path
     *
     * @return Carbon
     */
    public function fileModified($path)
    {
        try {
            return Carbon::createFromTimestamp($this->disk->lastModified($path));
        } catch (\Exception $e) {
            return Carbon::now();
        }
    }

    /**
     * Create a new directory.
     *
     * @param $folder
     *
     * @return bool
     */
    public function createDirectory($folder)
    {
        $folder = $this->cleanFolder($folder);
        if ($this->disk->exists($folder)) {
            $this->errors[] = 'Folder "'.$folder.'" already exists.';

            return false;
        }

        return $this->disk->makeDirectory($folder);
    }

    /**
     * Delete a directory.
     *
     * @param $folder
     *
     * @return bool
     */
    public function deleteDirectory($folder)
    {
        $folder = $this->cleanFolder($folder);
        $filesFolders = array_merge($this->disk->directories($folder), $this->disk->files($folder));
        if (!empty($filesFolders)) {
            $this->errors[] = 'The directory must be empty to delete it.';

            return false;
        }

        return $this->disk->deleteDirectory($folder);
    }

    /**
     * Delete a file.
     *
     * @param $path
     *
     * @return bool
     */
    public function deleteFile($path)
    {
        $path = $this->cleanFolder($path);
        if (!$this->disk->exists($path)) {
            $this->errors[] = 'File does not exist.';

            return false;
        }

        return $this->disk->delete($path);
    }

    /**
     * @param $path
     * @param $originalFileName
     * @param $newFileName
     *
     * @return bool
     */
    public function rename($path, $originalFileName, $newFileName)
    {
        $path = $this->cleanFolder($path);
        $nameName = $path.DIRECTORY_SEPARATOR.$newFileName;
        if ($this->disk->exists($nameName)) {
            $this->errors[] = 'The file "'.$newFileName.'" already exists in this folder.';

            return false;
        }

        return $this->disk->getDriver()->rename(($path.DIRECTORY_SEPARATOR.$originalFileName), $nameName);
    }

    /**
     * Show all directories that the selected item can be moved to.
     *
     * @return array
     */
    public function allDirectories()
    {
        $directories = $this->disk->allDirectories('/');

        return collect($directories)->filter(function ($directory) {
            return !(starts_with($directory, '.'));
        })->map(function ($directory) {
            return DIRECTORY_SEPARATOR.$directory;
        })->reduce(function ($allDirectories, $directory) {
            $parts = explode('/', $directory);
            $name = str_repeat('&nbsp;', (count($parts)) * 4).basename($directory);

            $allDirectories[$directory] = $name;

            return $allDirectories;
        }, collect())->prepend('Root', '/');
    }

    /**
     * @param   $currentFile
     * @param   $newFile
     *
     * @return bool
     */
    public function moveFile($currentFile, $newFile)
    {
        if ($this->disk->exists($newFile)) {
            $this->errors[] = 'File already exists.';

            return false;
        }

        return $this->disk->getDriver()->rename($currentFile, $newFile);
    }

    /**
     * @param $currentFolder
     * @param $newFolder
     *
     * @return bool
     */
    public function moveFolder($currentFolder, $newFolder)
    {
        if ($newFolder == $currentFolder) {
            $this->errors[] = 'Please select another folder to move this folder into.';

            return false;
        }

        if (starts_with($newFolder, $currentFolder)) {
            $this->errors[] = 'You can not move this folder inside of itself.';

            return false;
        }

        return $this->disk->getDriver()->rename($currentFolder, $newFolder);
    }

    /**
     * Return the full web path to a file.
     *
     * @param $path
     *
     * @return string
     */
    public function fileWebpath($path)
    {
        $path = $this->disk->url($path);
        // Remove extra slashes from URL without removing first two slashes after http/https:...
        $path = preg_replace('/([^:])(\/{2,})/', '$1/', $path);

        return $path;
    }

    /**
     * @param $path
     *
     * @return string
     */
    private function fileRelativePath($path)
    {
        $path = $this->fileWebpath($path);
        // @todo This wont work for files not located on the current server...
        $path = str_replace_first(env('APP_URL'), '', $path);
        $path = str_replace(' ', '%20', $path);

        return $path;
    }

    /**
     * This method will take a collection of files that have been
     * uploaded during a request and then save those files to
     * the given path.
     *
     * @param UploadedFilesInterface $files
     * @param string                 $path
     *
     * @return int
     */
    public function saveUploadedFiles(UploadedFilesInterface $files, $path = '/')
    {
        return $files->getUploadedFiles()->reduce(function ($uploaded, UploadedFile $file) use ($path) {
            $fileName = $file->getClientOriginalName();
            if ($this->disk->exists($path.$fileName)) {
                $this->errors[] = 'File '.$path.$fileName.' already exists in this folder.';

                return $uploaded;
            }

            if (!$file->storeAs($path, $fileName, $this->diskName)) {
                $this->errors[] = trans('media-manager::messages.upload_error', ['entity' => $fileName]);

                return $uploaded;
            }
            $uploaded++;

            return $uploaded;
        }, 0);
    }

    /**
     * Work out if an item (file or folder) is hidden (begins with a ".").
     *
     * @param $item
     *
     * @return bool
     */
    private function isItemHidden($item)
    {
        return starts_with(last(explode(DIRECTORY_SEPARATOR, $item)), '.');
    }
}
