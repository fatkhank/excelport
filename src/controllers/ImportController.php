<?php

namespace Hamba\ExcelPort\Controllers;

use Hamba\ExcelPort\Import;
use Storage;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ImportController extends BaseController
{
    use AuthorizesRequests{
        authorize as protected traitAuthorize;
    }
    
    use ValidatesRequests;
    use \App\Traits\QueryRequest;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $query = Import::query();
        $this->useSelect($query, Import::class);
        $this->useSort($query, Import::class);
        $this->useFilter($query, Import::class);
        
        //select mine
        $query->where('user_id', request()->user()->id);

        return $this->resultList($query);
    }

    /**
     * Display file metadata
     *
     * @param  string $uuid
     * @return \Illuminate\Http\Response
     */
    public function showStatus($uuid)
    {
        $query = Import::where('uuid', $uuid);
        $this->useSelect($query, Import::class);
        return $query->firstOrFail();
    }

    /**
     * Download result.
     *
     * @param  string $uuid
     * @return \Illuminate\Http\Response
     */
    public function downloadResult($uuid)
    {
        $import = Import::where('uuid', $uuid)
        ->where('user_id', request()->user()->id)
        ->firstOrFail();

        $content = Storage::get('imports/'.$import->getOutputFilename());
        
        //set filename
        $filename = $import->original_name;
        
        return response()->make(
            $content, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => "inline; filename=\"".urlencode($filename)."\""
            ]
        );
    }

    /**
     * Display the specified resource.
     *
     * @param  string $uuid
     * @return \Illuminate\Http\Response
     */
    public function show($uuid)
    {
        $query = Import::where('uuid', $uuid);
        $this->useSelect($query, Import::class);
        //TODO show content
        //custom select content
        $this->addSelectable($query, ["content"]);

        return $query->firstOrFail();
    }

    /**
     * Cancel before validation start
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\Response
     */
    public function cancel(string $uuid)
    {
        $import = Import::where('uuid', $uuid)
            ->where('user_id', request()->user()->id)
            ->firstOrFail();

        //ensure upload success and validation not yet started
        if($import->status != Import::STATE_UPLOADED){
            abort(403, "State forbid operation");
        }

        //update status
        $import->status = Import::STATE_CANCELED;
        $import->save();
    }

    /**
     * Stop validation
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\Response
     */
    public function stop(string $uuid)
    {
        $import = Import::where('uuid', $uuid)
            ->where('user_id', request()->user()->id)
            ->firstOrFail();

        if($import->status != Import::STATE_VALIDATING){
            abort(403, "State forbid operation");
        }

        //update status
        $import->status = Import::STATE_STOPPED;
        $import->save();
    }
}
