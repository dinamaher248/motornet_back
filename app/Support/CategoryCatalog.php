<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
class CategoryCatalog {
    public static function all(?string $onlySection = null, bool $includeTransport = false): array {
        $items=[]; $seen=[];
        if (!Schema::hasTable("service_categories")) return [];
        $q=DB::table("service_categories");
        if (Schema::hasColumn("service_categories","is_active")) $q->where(fn($w)=>$w->where("is_active",1)->orWhereNull("is_active"));
        foreach ($q->orderBy("id")->get() as $row) {
            $sec=strtolower(trim((string)($row->hub_section??"orders")));
            if($sec==="transport"&&!$includeTransport)continue;
            if($onlySection!==null&&$sec!==$onlySection)continue;
            $raw=$row->icon??$row->image??null;
            $img=$raw?(str_starts_with($raw,"http")?$raw:asset("storage/".ltrim($raw,"/"))):null;
            $items[]=["id"=>(int)$row->id,"slug"=>$row->slug??"cat-".$row->id,"name"=>$row->name,"hub_section"=>$sec,"image"=>$img,"icon"=>$img,"max_images"=>(int)($row->max_images??10)?:10,"is_active"=>true];
        }
        return $items;
    }
}
