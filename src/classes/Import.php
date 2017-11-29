<?php

namespace Hamba\ExcelPort;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    use \Hamba\QueryGet\Selectable;
    use \Hamba\QueryGet\Filterable;
    use \Hamba\QueryGet\Sortable;

    const STATE_UPLOADING = 'UPLOADING';
    const STATE_UPLOADED = 'UPLOADED';
    /**
     * Stopped before validation start
     */
    const STATE_CANCELED = 'CANCELED';
    const STATE_VALIDATING = 'VALIDATING';
    /**
     * Validation process stoped
     */
    const STATE_STOPPED = 'STOPPED';
    /**
     * Validation failed
     */
    const STATE_INVALID = 'INVALID';
    /**
     * Validation success, still processing
     */
    const STATE_PROCESSING = 'PROCESSING';

    /**
     * Processing success
     */
    const STATE_PROCESSED = 'PROCESSED';

    /**
     * Fail to process
     */
    const STATE_PROCESS_FAILED = 'PROCESS_FAILED';

    protected $table = 'import_excels';
    
    protected $fillable = [
        'uuid', 'original_name', 'format', 'tag', 'user_id', 'status'
    ];

    public $selectable = [
        'uuid', 'total_rows', 'validated_rows', 'processed_rows', 'errors_count', 'status', 'original_name'
    ];

    public $filterable = [
        'status', 'original_name', 'tag'
    ];

    public $sortable = [
        'created_at'
    ];

    protected $hidden = [
        'id', 'updated_at'
    ];

    public function user(){
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function getInputDir(){
        return storage_path('app/imports/');
    }

    public function getInputFilename(){
        return $this->uuid.'.'.$this->format;
    }

    public function getInputFilepath(){
        return $this->getInputDir().$this->getInputFilename();
    }

    public function getOutputDir(){
        return storage_path('app/imports/');
    }

    public function getOutputFilename(){
        return $this->uuid.'.'.$this->format;
    }

    public function getOutputFilepath(){
        return $this->getOutputDir().$this->getOutputFilename();
    }
}
