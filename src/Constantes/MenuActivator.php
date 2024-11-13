<?php
namespace App\Constantes;

class MenuActivator
{
    public const GROUPE_FINANCE = 1;
    public const GROUPE_MARKETING = 2;
    public const GROUPE_PRODUCTION = 3;
    public const GROUPE_CLAIMS = 4;
    public const GROUPE_REPORTING = 5;
    public const GROUPE_ADMINISTRATION = 6;

    public function __construct(private int $groupe_menu)
    {
        
    }

    public function canShow($groupe_menu):string
    {
        if($groupe_menu == $this->groupe_menu){
            return "show";
        }else{
            return "";
        }
    }

    public function canExpand($groupe_menu):string
    {
        if($groupe_menu == $this->groupe_menu){
            return "true";
        }else{
            return "false";
        }
    }

    public function isCollapsed($groupe_menu):string
    {
        if($groupe_menu == $this->groupe_menu){
            return "";
        }else{
            return "collapsed";
        }
    }
}
