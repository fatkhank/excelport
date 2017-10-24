<?php

namespace Hamba\ExcelPort;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maatwebsite\Excel\Filters\ChunkReadFilter;
use \Maatwebsite\Excel\Facades\Excel;

class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Import entry
     *
     * @var Import
     */
    protected $import;

    /**
     * Purpose of upload
     *
     * @var string
     */
    protected $action;

    /**
     * Size of chunk
     *
     * @var integer
     */
    protected $chunkSize = 100;

    /**
     * Name of column contains validation messages
     *
     * @var string
     */
    protected $validationColumn = 'validation';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Import $import, $action = 'put')
    {
        $this->import = $import;
        $this->action = $action;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //check if canceled
        if($this->import->status == Import::STATE_CANCELED){
            //no need to continue process
            return;
        }

        //load file for read
        $inputFile = $this->import->getInputFilepath();
        $reader = Excel::selectSheets('DATA')->load($inputFile, null, null, true);
        $reader->reader->setReadDataOnly(true);

        // Get total rows
        $totalRows = $reader->getTotalRowsOfFile();
        $this->import->total_rows = $totalRows - 1; //without headers
        $this->import->validated_rows = null;
        $this->import->errors_count = null;
        $this->import->processed_rows = null;
        $this->import->save();

        //setup chunk filter
        $filter = new ChunkReadFilter();
        $reader->reader->setReadFilter($filter);

        //setup output
        $outFile = $this->import->getOutputFilepath();
        $out = Excel::load($outFile, null, null, true);
        $writer = $out->sheet(0);
        $writer->setAutosize(true);
        //save output
        $out->store($this->import->format, $this->import->getOutputDir());
        
        //do validation
        $isValid = $this->doValidation($reader, $filter, $out, $writer);

        //do process
        if ($isValid) {
            $this->doProcess($reader, $filter, $writer);
        } else {
            $this->import->status = Import::STATE_INVALID;
        }
        $this->import->save();

        \Log::debug('Job done');
    }

    protected function doValidation($reader, $filter, $out, $writer)
    {
        //default is no error
        $isValid = true;
        $totalRows = $this->import->total_rows + 1;//add header

        //reset counter
        $this->import->validated_rows = 0;
        $this->import->errors_count = 0;
        $this->import->status = Import::STATE_VALIDATING;
        $this->import->save();

        //find validation rules
        $ruleMethod = 'rulesTo'.studly_case($this->action);
        if (method_exists($this, $ruleMethod)) {
            //get rules
            $rules = $this->$ruleMethod();
        }

        //validate each chunk
        for ($startRow = 0; $startRow < $totalRows; $startRow += $this->chunkSize) {
            // Set start index
            $startIndex = ($startRow == 0) ? $startRow : $startRow - 1;
            $chunkSize  = ($startRow == 0) ? $this->chunkSize + 1 : $this->chunkSize;
            
            // Set the rows for the chunking
            $filter->setRows($startRow, $chunkSize);
            // Slice the results
            $results = $reader->get()->slice($startIndex, $chunkSize);

            //count rows
            $counter = 0;
            $errors = 0;

            //dispatch validation
            foreach ($results as $line => $row) {
                $values = $row->toArray();

                //validate using rules
                if (isset($rules)) {
                    $validator = \Validator::make($values, $rules);
                    
                    if ($validator->fails()) {
                        $isValid = false;
                        $errors++;

                        //concat validation messages for each column
                        $msgs = array_map(function ($msgs) {
                            return implode('; ', $msgs);
                        }, $validator->errors()->getMessages());
        
                        //concat validation for all column
                        $values[$this->validationColumn] = implode('; ', $msgs);

                        //write to original file
                        $writer->row($line + 2, $values);
                    }
                }

                $counter++;
            }

            //update progress
            $this->import->fresh();
            $this->import->validated_rows += $counter;
            $this->import->errors_count += $errors;
            $this->import->save();

            //save output file
            if ($errors > 0) {
                $out->store($this->import->format, $this->import->getOutputDir());
            }

            //check if canceled
            if ($this->import->status == Import::STATE_STOPPED) {
                goto VALIDATION_END;
            }
        }

        
        VALIDATION_END:

        //return validation result
        return $isValid;
    }

    protected function doProcess($reader, $filter)
    {
        $totalRows = $this->import->total_rows;
        
        //mark status
        $this->import->status = Import::STATE_PROCESSING;
        $this->import->save();
        
        //find validation rules
        $processMethod = camel_case($this->action);
        if (!method_exists($this, $processMethod)) {
            //no processing required
            return;
        }

        //validate each chunk
        for ($startRow = 0; $startRow < $totalRows; $startRow += $this->chunkSize) {
            \Log::debug('Process start '.$startRow);

            // Set start index
            $startIndex = ($startRow == 0) ? $startRow : $startRow - 1;
            $chunkSize  = ($startRow == 0) ? $this->chunkSize + 1 : $this->chunkSize;
            
            // Set the rows for the chunking
            $filter->setRows($startRow, $chunkSize);
            // Slice the results
            $results = $reader->get()->slice($startIndex, $chunkSize);

            //counter line
            $counter = 0;

            //process each chunk rows
            foreach ($results as $line => $row) {
                $values = (object)$row->toArray();

                //eat each using rules
                $this->$processMethod($values);

                $counter++;
            }

            //update progress
            $this->import->fresh();
            $this->import->processed_rows += $counter;
            $this->import->save();
        }

        //mark status
        $this->import->status = Import::STATE_PROCESSED;
        $this->import->save();
    }
}
