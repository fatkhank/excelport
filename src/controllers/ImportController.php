<?php

namespace Hamba\ExcelPort\Controllers;

use Hamba\ExcelPort\Import;
use Storage;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;

class ImportController extends BaseController
{    
    use ValidatesRequests;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $qg = qg(Import::class)->apply();
        
        //select mine
        $user = request()->user();
        if($user){
            $qg->query->where('user_id', $user->id);
        }
        
        return $qg->get();
    }

    /**
     * Display file metadata
     *
     * @param  string $uuid
     * @return \Illuminate\Http\Response
     */
    public function showStatus($uuid)
    {
        return qg(Import::where('uuid', $uuid))->select()->fof();
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
        $query = qg(Import::where('uuid', $uuid))->select;
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
