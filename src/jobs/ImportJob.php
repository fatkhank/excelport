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
     * If true, processing will be wrapped in transaction
     *
     * @var boolean
     */
    protected $transaction = true;

    /**
     * Default upload action. (Can be overriden in row_action)
     *
     * @var string
     */
    protected $defaultAction = null;

    /**
     * Name of column contains row action
     *
     * @var string
     */
    protected $actionColumn = 'row_action';

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
    protected $validationColumn = 'row_validation';

    /**
     * List of validation rules mapped by action name
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * List of processing methods keyed by action name
     *
     * @var array
     */
    protected $processors = [];    

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Import $import, $defaultAction = null)
    {
        $this->import = $import;
        $this->defaultAction = $defaultAction;
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
            $this->doProcess($reader, $filter, $out, $writer);
        } else {
            $this->import->status = Import::STATE_INVALID;
        }
        $this->import->save();

        \Log::debug('Job done');
    }

    /**
     * Get action name for row
     *
     * @param mixed $row Row values
     * @return void
     */
    protected function getRowAction($row){
        $values = (array)$row;
        $actionColumn = $this->actionColumn;

        //row action can override default action
        return $values[$actionColumn] ?? $this->defaultAction;
    }

    
    /**
     * Get validation rules for specific action
     *
     * @param string $actionName
     * @return void
     */
    protected function getValidationRules(string $actionName){
        //find in cache
        if(array_key_exists($actionName, $this->validationRules)){
            return $this->validationRules[$actionName];
        }

        //find validation rules by method
        $ruleMethod = 'rulesTo'.studly_case($actionName);
        if (method_exists($this, $ruleMethod)) {
            //get rules
            $rules = $this->$ruleMethod();
            //add to list
            $this->validationRules[$actionName] = $rules;

            return $rules;
        }

        //no validation found
        return null;
    }

    /**
     * Do validation
     *
     * @param [type] $reader
     * @param [type] $filter
     * @param [type] $out
     * @param [type] $writer
     * @return void
     */
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

                //find rules
                $rowAction = $this->getRowAction($values);
                if(!$rowAction){
                    continue;
                }
                $rules = $this->getValidationRules($rowAction);

                //validate using rules
                if (!empty($rules)) {
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

    /**
     * Get function that can process action
     *
     * @param string $actionName
     * @return string
     */
    protected function getProcessor(string $actionName){
        if(!array_key_exists($actionName, $this->processors)){
            //find process function
            $processMethod = camel_case($actionName);
            if (!method_exists($this, $processMethod)) {
                //no processing required
                return $processMethod = null;
            }

            //add to cache
            $this->processors[$actionName] = $processMethod;
        }

        //return cached process function
        return $this->processors[$actionName];
    }

    /**
     * Process excel after pass validation
     *
     * @param [type] $reader
     * @param [type] $filter
     * @return void
     */
    protected function doProcess($reader, $filter, $out, $writer)
    {
        $totalRows = $this->import->total_rows;
        
        //mark status
        $this->import->status = Import::STATE_PROCESSING;
        $this->import->save();

        //wrap transaction
        if($this->transaction){
            \DB::beginTransaction();
        }

        //process each chunk
        for ($startRow = 0; $startRow < $totalRows; $startRow += $this->chunkSize) {
            \Log::debug('Process start '.$startRow);

            // Set start index
            $startIndex = ($startRow == 0) ? $startRow : $startRow - 1;
            $chunkSize  = ($startRow == 0) ? $this->chunkSize + 1 : $this->chunkSize;
            
            // Set the rows for the chunking
            $filter->setRows($startRow, $chunkSize);
            // Slice the results
            $slicedRows = $reader->get()->slice($startIndex, $chunkSize);

            //counter line
            $counter = 0;

            //process each chunk rows
            foreach ($slicedRows as $line => $row) {
                $values = $row->toArray();

                //find processor
                $rowAction = $this->getRowAction($values);
                if(!$rowAction){
                    continue;
                }
                $processMethod = $this->getProcessor($rowAction);
                
                //process row as object for ease
                $results = $this->$processMethod((object)$values);
                
                //write result
                if($results){
                    //ensure array
                    $results = (array)$results;

                    //patch result to values
                    foreach ($results as $key => $result) {
                        $values[$key] = $result;
                    }
                    
                    //write to original file
                    $writer->row($line + 2, $values);
                }

                $counter++;
            }

            //update progress
//            $this->import->fresh();
            $this->import->processed_rows += $counter;
            $this->import->save();
        }

        //commit
        if($this->transaction){
            \DB::commit();
        }

        //mark status
        $this->import->status = Import::STATE_PROCESSED;
        $this->import->save();

        //save output file
        // if ($errors > 0) {
        //     $out->store($this->import->format, $this->import->getOutputDir());
        // }
    }

    /**
     * The job failed to process.
     *
     * @param  Exception  $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        if($this->import->status == Import::STATE_PROCESSING){
            //rollback on transaction
            if($this->transaction){
                \DB::rollback();
            }

            //mark status
            $this->import->status = Import::STATE_PROCESS_FAILED;
            $this->import->save();

            \Log::debug('Process failed ');
        }
    }
}
