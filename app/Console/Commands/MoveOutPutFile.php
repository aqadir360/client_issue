<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;


class MoveOutPutFile extends Command
{
    protected $signature = 'dcp:move_output_file';
    protected $description = 'Move output files to an external storage bucket';



    public function handle()
    {
        $this->fileUpload(); // to upload files on Azure storage
        // $this->getFiles(); // to get files uploaded on Azure storage

    }

    public function getFiles()
    {
        $outFilesAzure = Storage::disk('azure');
        $filesAzure = $outFilesAzure->allFiles();
        echo "fileCount-".count($filesAzure)." - ";
        foreach($filesAzure as $file){
            echo $file.",";
        }
    }

    public function fileUpload(){

        $outFiles = Storage::disk('imports_output_files');
        $files = $outFiles->allFiles();
        $directoryToUpload = date("Y")."/".date('m')."/";

        foreach ($files as $file) {
            Storage::disk('azure')->put($directoryToUpload.basename($file), $outFiles->get($file));
            $outFiles->delete($file);
        }
   }

}
?>
