<?php
namespace SoulDoit\DataTable;

use DB;
use App;
use Arr;

class SSP{
    
    /*
    |--------------------------------------------------------------------------
    | DataTable SSP for Laravel
    |--------------------------------------------------------------------------
    |
    | Author    : Syamsoul Azrien Muda (+60139584638)
    | Website   : https://github.com/syamsoulcc
    |
    */
    
    private $model;
    private $is_model=true;
    private $table;
    private $table_prefix;
    private $request;
    private $cols_info=[];
    private $cols;
    //private $cols_db_k;
    private $cols_dt_k;
    private $cols_arr=[];
    private $cols_raw_arr=[];
    private $cols_exc_arr=[];
    private $normal_data;
    private $dt_arr;
    private $total_count;
    private $filter_count;
    private $where_query=[];
    private $join_query=[];
    private $with_related_table;
    private $group_by;
    private $order;
    private $theSearchKeywordFormatter;
    
    function __construct($model, $cols){
        $this->table_prefix = DB::getTablePrefix() ?? "";
                
        $this->request  = request()->all();
             
        if(class_exists($model)){
            $this->model    = $model;
            $this->table = (new $model())->getTable();
        }else{
            $this->table = $model;
            $this->is_model = false;
        }
    
        foreach($cols as $e_key => $e_col){
            if(isset($e_col['db'])){
                if(is_a($e_col['db'], get_class(DB::raw('')))){
                    $e_col_db_arr = explode(" AS ", $e_col['db']->getValue());
                    
                    array_push($this->cols_arr, $e_col['db']);
                    array_push($this->cols_raw_arr, trim($e_col_db_arr[0]));
                    
                    $cols[$e_key]['db'] = strtr(trim($e_col_db_arr[1]), ['`'=>'']);
                }else{
                    $e_col_arr = explode('.', $e_col['db']);
                    if(count($e_col_arr) > 1) {
                        $e_col_db_name = $e_col['db'];
                        if($e_col_arr[0] != $this->table) $e_col_db_name .= " AS ".$e_col_arr[0].".".$e_col_arr[1];
                    }
                    else $e_col_db_name = $this->table . '.' . $e_col['db'];
            
                    if(!in_array($e_col_db_name, $this->cols_arr)) array_push($this->cols_arr, $e_col_db_name);
                    
                    $cols[$e_key]['db'] =  Arr::last(explode(" AS ", $e_col_db_name));
                    
                    $e_cdn_arr = explode('.', $cols[$e_key]['db']);
                    array_push($this->cols_raw_arr, '`' . $this->table_prefix.$e_cdn_arr[0] . '`.`' . $e_cdn_arr[1] . '`');
                }
            }
            if(!isset($e_col['dt'])) $cols[$e_key]['dt'] = null; 
        }
        
        $this->cols     = $cols;
        //$this->cols_db_k   = array_combine(array_column($cols, 'db'), $cols);
        $this->cols_dt_k   = array_combine(array_column($cols, 'dt'), $cols);
        
        ksort($this->cols_dt_k);
        
        foreach($this->cols_dt_k as $e_key => $e_col){
            if(is_numeric($e_col['dt'])){
                if(isset($e_col['db'])) array_push($this->cols_exc_arr, $e_col['db']);
                array_push($this->cols_info, ['label'=>($e_col['label'] ?? ""), 'class'=>($e_col['class'] ?? "")]);
            }else unset($this->cols_dt_k[$e_key]);
        }
    }
    
    public function getInfo(){
        $ret = [
            'labels'    => [],
            'order'     => $this->order ?? [[0, 'asc']],
        ];
        foreach($this->cols_info as $key=>$val) array_push($ret['labels'], ['title'=>$val['label'], 'className'=>$val['class']]);
        
        return $ret;
    }
    
    public function getNormalData(){
        
        if(empty($this->normal_data)){
            $req = $this->request;
            $cdtk = $this->cols_dt_k;            
            
            if(isset($req['draw']) && isset($req['order']) && isset($req['start']) && isset($req['length'])){
                
                $extra_cols = [];
                if(!empty($req['search']['value'])){
                    $col_search_str = "CONCAT(COALESCE(".implode($this->cols_raw_arr, ",''),' ',COALESCE(").",'')) AS `filter_col`";
                    array_push($extra_cols, DB::raw($col_search_str));
                }
                
                $the_cols = array_merge($this->cols_arr, $extra_cols);
                if($this->is_model){
                    if(empty($this->with_related_table)) $obj_model = ($this->model)::select($the_cols);
                    else $obj_model = ($this->model)::with($this->with_related_table)->select($the_cols);
                }else{
                    $obj_model = DB::table($this->table)->select($the_cols);
                    if(!empty($this->join_query)) foreach($this->join_query as $e_jqry){
                        if($e_jqry[0] == "left") $obj_model = $obj_model->leftJoinSub($e_jqry[1], $e_jqry[2], $e_jqry[3]);
                    }
                }
                
                if(!empty($this->where_query)) foreach($this->where_query as $e_qry){
                    if($e_qry[0] == "and") $obj_model = $obj_model->where($e_qry[1]);
                    elseif($e_qry[0] == "or") $obj_model = $obj_model->orWhere($e_qry[1]);
                }
                
                

                if(!empty($this->group_by)){
                    $gb_arr = explode(".", $this->group_by);
                    if(count($gb_arr) > 1){
                        $table  = $this->table_prefix . $gb_arr[0];
                        $column = $gb_arr[1];
                    }else{
                        $table  = $this->table_prefix . $this->table;
                        $column = $gb_arr[0];
                    }
                    $this->total_count = $obj_model->count(DB::raw("DISTINCT `$table`.`$column`"));
                    $obj_model = $obj_model->groupBy($this->group_by);
                }else $this->total_count = $obj_model->count();

                if(!empty($req['search']['value'])){
                    $query_search_value = '%'.$req['search']['value'].'%';
                    $obj_model = $obj_model->having('filter_col', 'LIKE', $query_search_value);
                    //$this->filter_count = $obj_model->count();
                    $this->filter_count = DB::select("SELECT count(*) AS `c` FROM (".$obj_model->toSql().") AS `temp_count_table`", array_merge($obj_model->getBindings(), [$query_search_value]))[0]->c;
                }else{
                    $this->filter_count = $this->total_count;
                }
                
                $obj_model = $obj_model->orderBy($cdtk[$req['order'][0]['column']]['db'], $req['order'][0]['dir']);
                
                if($req['length'] > -1) $obj_model = $obj_model->offset($req['start'])->limit($req['length']);
                //dd($obj_model->toSql());
                $this->normal_data = $obj_model->get();
            }else{
                $this->normal_data = false;
            }
        }
        
        return $this->normal_data;
    }
    
    public function getDtArr(){
        $ret_data = [];
        if(empty($this->dt_arr)){
            $req = $this->request;
            
            $m_data = $this->getNormalData();
            $e_cdtk = $this->cols_dt_k;

            if(!empty($m_data)){
                $n_data = $m_data->toArray();
                foreach($n_data as $e_key => $e_ndat){
                    foreach($e_cdtk as $ee_key => $ee_val){
                        if(is_numeric($ee_key)){
                            if(isset($ee_val['db'])){
                                $ee_val_db_arr = explode('.', $ee_val['db']);
                                $ee_val_db_name = ($ee_val_db_arr[0] != $this->table) ? $ee_val['db'] : Arr::last($ee_val_db_arr);
                                $the_val = $m_data[$e_key]->{$ee_val_db_name};
                            }else{
                                $the_val = null;
                            }
                            
                            if(!empty($req['search']['value'])){
                                $search_val = $req['search']['value'];
                                
                                if(is_callable($this->theSearchKeywordFormatter)){
                                    if(is_string($the_val) || is_numeric($the_val)) $the_val = strtr($the_val, [$search_val=>($this->theSearchKeywordFormatter)($search_val)]);
                                }
                            }
                            
                            if(isset($ee_val['formatter']) && is_callable($ee_val['formatter'])) $ret_data[$e_key][$ee_key] = $ee_val['formatter']($the_val, $m_data[$e_key]);
                            else $ret_data[$e_key][$ee_key] = $the_val;
                        }
                    }
                }
            }
            
            $this->dt_arr = [
                'draw' => $req['draw'] ?? 0,
                'recordsTotal' => $this->total_count,
                'recordsFiltered' => $this->filter_count,
                'data' => $ret_data,
            ];
        }
        
        return $this->dt_arr;
    }
    
    public function where(...$params){
        $ret_query = false;
        
        if(is_callable($params[0])){
            $ret_query = $params[0];
        }elseif(count($params) == 2){
            $ret_query = function($query) use($params){
                $query->where($params[0], $params[1]);
            };
        }elseif(count($params) == 3){
            $ret_query = function($query) use($params){
                $query->where($params[0], $params[1], $params[2]);
            };
        }
        
        if($ret_query !== false) array_push($this->where_query, ['and', $ret_query]);
        
        return $this;
    }
    
    public function orWhere(...$params){
        $ret_query = false;
        
        if(is_callable($params[0])){
            $ret_query = $params[0];
        }elseif(count($params) == 2){
            $ret_query = function($query) use($params){
                $query->where($params[0], $params[1]);
            };
        }elseif(count($params) == 3){
            $ret_query = function($query) use($params){
                $query->where($params[0], $params[1], $params[2]);
            };
        }
        
        if($ret_query !== false) array_push($this->where_query, ['or', $ret_query]);
        
        return $this;
    }
    
    public function leftJoin($table, ...$columns){
        if(isset($columns[0]) && is_callable($columns[0])){
            $extend_query = $columns[0];
            unset($columns[0]); 
            $columns=array_values($columns);
        }
        
        if(count($columns) == 2){
            $table = explode(":", $table);
            
            $table_name = $table[0];
            $table_name_arr = explode(" AS ", $table_name);
            
            if(count($table_name_arr) > 1){
                $table_name = trim($table_name_arr[0]);
                $table_alias_name = trim($table_name_arr[1]);
                
                $full_table_alias_name = (config('sd-datatable-ssp.leftjoin.alias_has_prefix') ? $this->table_prefix : '') . $table_alias_name;          
            }
            $full_table_name = (config('sd-datatable-ssp.leftjoin.alias_has_prefix') ? $this->table_prefix : '') . $table_name;
            
            
            $db_table = DB::table($table_name);
            
            if(!empty($table[1])){
                $cols = explode(",", $table[1]);
                foreach($cols as $key=>$e_col){
                    $e_col = trim($e_col);
                    if(count(explode(".", $e_col)) > 1) $cols[$key] = $e_col;
                    else $cols[$key] = $table_name . '.' . $e_col;
                }
                $db_table = $db_table->select($cols);
            }
            
            if(isset($extend_query) && is_callable($extend_query)){
                $extend_query($db_table);
            }
            
            array_push($this->join_query, [
                'left', $db_table, ($full_table_alias_name ?? $full_table_name), function($join) use($columns){
                    $join->on($columns[0], '=', $columns[1]);
                }
            ]);
        }else $is_model = true;
        
        $this->is_model = $is_model ?? false;
        
        return $this;
    }
    
    public function with($related_table){
        $this->with_related_table = $related_table;
        
        return $this;
    }
    
    public function groupBy($group_by){
        $this->group_by = $group_by;
        
        return $this;
    }
    
    public function order($dt, $sort){
        $this->order = [[$dt, $sort]];
        
        return $this;
    }
    
    public function sort($dt, $sort){
        return $this->order($dt, $sort);
    }
    
    public function searchKeywordFormatter($formatter){
        if(is_callable($formatter)){
            $this->theSearchKeywordFormatter = $formatter;
        }
        
        return $this;
    }
}

?>