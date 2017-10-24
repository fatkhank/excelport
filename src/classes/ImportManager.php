<?php

namespace Hamba\ExcelPort;

use \Maatwebsite\Excel\Facades\Excel;
use \Ramsey\Uuid\Uuid;
use Illuminate\Http\File;

class ImportManager
{
    protected $availableFormats = ['xls', 'xlsx', 'csv'];

    /**
     * Import id
     *
     * @var Uuid
     */
    protected $uuid;

    /**
     * The import model
     *
     * @var Import
     */
    protected $import;

    /**
     * Import action
     *
     * @var string
     */
    protected $action;

    /**
     * Import tag
     *
     * @var string
     */
    protected $tag;

    /**
     * Create a new imported excel file instance.
     *
     * @return void
     */
    public function __construct()
    {
    }
    
    /**
     * Get intended import action
     *
     * @return string
     */
    public function getAction()
    {
        if (!$this->action) {
            $action = request('import_action');
            if ($action) {
                $this->action = strtolower($action);
            }
        }
        return $this->action;
    }

    /**
     * Get Tag
     *
     * @return string
     */
    public function getTag()
    {
        if (!$this->tag) {
            $tag = request('import_tag');
            if ($tag) {
                $this->tag = strtolower($tag);
            }
        }
        return $this->tag;
    }

    /**
     * Load file from request
     *
     * @return void
     */
    public function loadRequest($inputName = 'file')
    {
        $file = request()->file($inputName);

        if (!$file) {
            //file input is requried
            abort(422, $inputName. ' required');
        }

        //ensure file successfully uploaded
        if (!$file->isValid()) {
            return null;
        }

        //validate extension
        $format = $file->getClientOriginalExtension();
        if (!in_array($format, $this->availableFormats)) {
            abort(422, 'Format '.$format.' not allowed');
        }
        
        //move to import
        $this->uuid = Uuid::uuid4();

        //create entry in database
        $this->import = new Import([
            'uuid' => $this->uuid,
            'original_name' => $file->getClientOriginalName(),
            'format' => $format,
            'tag' => $this->getTag(),
            'status' => Import::STATE_UPLOADED
        ]);
        //save user
        $this->import->user()->associate(request()->user());
        $this->import->save();

        //move file
        $file->storeAs('imports', $this->import->getInputFilename());
        
        return $this;
    }

    /**
     * Dispatch process to job
     *
     * @return void
     */
    public function dispatch($job)
    {
        if ($this->import) {
            $job::dispatch($this->import, $this->action)
            ->onQueue(config('xltools.import_queue'));
        }
    }

    /**
     * Get response
     *
     * @return void
     */
    public function response()
    {
        return response()->json([
            'id' =>  $this->import->uuid
        ]);
    }
}
