<?php

namespace Hamba\ExcelPort;

use \Maatwebsite\Excel\Facades\Excel;

class ExportManager
{
    public $availableFormats = ['xls', 'xlsx', 'csv'];

    
    protected $excel;

    protected $headings;

    protected $sheet;

    /**
     * Load excel template
     *
     * @param [type] $templateName
     * @return void
     *
     */
    public function template($templateName)
    {
        $templatePath = config('xltools.template_path', 'storage/app/excel/templates/').$templateName;
        
        //get subtemplate
        $subtemplate= request('template');
        if ($subtemplate) {
            $templatePath .= '.'.$subtemplate;
        }

        //add extension then load
        $this->excel = \Excel::load($templatePath.'.xlsx');
        
        //get headings
        $rows = $this->excel->all();
        if ($rows) {
            $this->headings = $rows->getHeading();
        }

        // $sheet = $excel->sheet(0);
        // $sheet->setAutosize(true);

        //for chaining
        return $this;
    }

    /**
     * Write data to sheet
     *
     * @param [type] $excel
     * @param [type] $rows
     * @param string $startCell
     * @return void
     */
    public function fill($rows, $startCell = 'A2')
    {
        $sheet = $this->excel->sheet(0);
        $sheet->setAutosize(true);

        //match column by headings
        if (!empty($this->headings)) {
            $rows = collect($rows)->map(function ($row) {
                $sorted = [];
                foreach ($this->headings as $heading) {
                    $sorted[] = array_get($row, $heading);
                }
                return $sorted;
            });
        }

        //todo fill only attribute specified in header
        $sheet->fromArray($rows, null, $startCell, false, false);
        return $this;
    }

    /**
     * Export excel, with requested format and disposition
     *
     * @param [type] $excel
     * @param string $filename
     * @return void
     */
    public function serve($filename = null)
    {
        //set format
        $format = strtolower(trim(request('format', 'xlsx')));

        //get default name
        if ($filename === null) {
            $filename = $this->excel->getFileName();
        }
        //add timestamp
        $filename .= '-'. date('Ymd');

        //update name
        $this->excel->setFileName($filename);
        

        $headers = [];
        
        //override header
        if (request("out") === "inline") {
            $headers["Content-Disposition"] = "inline; filename=\"".urlencode($filename)."\"";
        }

        return $this->excel->export($format, $headers);
    }

    public function getExcel()
    {
        return $this->excel;
    }
}
