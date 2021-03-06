<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Flat extends Model
{
    protected $fillable = [
        'street', 'house_number', 'description', 'floor', 'area', 'living_area', 'number_of_rooms', 'city',
            'type_of_premises', 'rental_period', 'price', 'photos', 'status','user_id',
    ];

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function orders()
    {
        return $this->hasMany('App\FlatServiceOrder');
    }

    public function service_orders()
    {
        return $this->hasMany('App\HouseholdServiceOrder');
    }

    public function dialog()
    {
        return $this->hasMany('App\Dialog');
    }


    public function calculateReservedDates() {
        $daysFlat = [];
        $daysService = [];
        $this->calculateOrders($daysFlat);
        $this->calculateServiceOrders($daysService);
        return ['flatDates' => array_unique($daysFlat), 'serviceDates' => array_unique($daysService)];
    }

    private function calculateOrders(&$days) {
        foreach ($this->orders as $order) {
            $days = array_merge($days, $this->calculateDays($order->date_start, $order->date_end));
        }
    }

    private function calculateServiceOrders(&$days) {
        foreach ($this->service_orders as $order) {
            $days = array_merge($days, [$order->date_of_completion]);
        }
    }

    private function calculateDays($date_start, $date_end) {
        $dayInTyme = 86400;
        $date_start = strtotime($date_start);
        $date_end = strtotime($date_end);
        $numDays = round(($date_end - $date_start) / $dayInTyme) + 1;
        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $days[] = date('Y-m-d', ($date_start + ($i * $dayInTyme)));
        }
        return $days;
    }

    public static function filtrateFlat(Request $request, $itemPerPage = 20) {
        $query = self::addSearchQuery($request, self::where('id', '<>', 0));
        $query = self::addSortQuery($request, $query);
        $flats = $query->paginate($itemPerPage);
        $flats->withPath(route('flat-search', $request->except('page')));
        return $flats;
    }

    private static function addSearchQuery(Request $request, $query) {
        if($request->city) {
            $query = $query->where('city', 'like', "%{$request->city}%" );
        }
        if($request->price_start) {
            $query = $query->where('price', '>=', $request->price_start );
        }
        if($request->price_end) {
            $query = $query->where('price', '<=', $request->price_end );
        }
        if($request->type_of_premises) {
            $query = $query->where('type_of_premises', $request->type_of_premises );
        }
        if($request->rental_period) {
            $query = $query->where('rental_period', $request->rental_period );
        }
        if($request->number_of_rooms) {
            if($request->number_of_rooms === '4+') {
                $query = $query->where('number_of_rooms', '>=', 4);
            }else  {
                $query = $query->where('number_of_rooms', $request->number_of_rooms);
            }
        }
        return $query;
    }

    private static function addSortQuery(Request $request, $query) {
        switch($request->order) {
            default:case 'new': return $query->orderBy('id', 'desc');
            case 'last': return $query->orderBy('id', 'asc');
            case 'price_asc': return $query->orderBy('price', 'asc');
            case 'price_desc': return $query->orderBy('price', 'desc');
            case 'popular_asc': return $query->withCount('orders')->orderBy('orders_count', 'asc');
            case 'popular_desc': return $query->withCount('orders')->orderBy('orders_count', 'desc');
        }
    }

    public function uploadImages(Request $request, $isDelete = true) {
        $files = $request->file('photos'); $arrayPhotos = [];
        if($request->hasFile('photos')) {
            foreach ($files as $file) {
                $date = date('FY');
                $path = Storage::disk('public')->putFileAs("flats/$date", $file,Str::random(20) . '.' . $file->extension());
                $arrayPhotos[] = str_replace('public', '', $path);
            }
        }
        if(!$isDelete) {
            if($this->photos != '["flats\\\\default.png"]') {
                $photosLast = json_decode($this->photos);
                $arrayPhotos = array_merge($photosLast, $arrayPhotos);
            }
        }
        if(count($arrayPhotos) == 0) {
            $this->photos = '["flats\\\\default.png"]';
        } else {
            $this->photos = json_encode($arrayPhotos);
        }
    }

    public function updateImages($request) {
        $this->uploadImages($request, false);
    }

    public function deleteImages($id = null) {
        if($this->photos != '["flats\\\\default.png"]') {
            $files = json_decode($this->photos);
            $i = 1;
            foreach ($files as $file) {
                if($id === $i || $id === null) {
                    if($id !== null) {
                        array_splice ($files, $id-1, 1);
                    }
                    if($file !== 'flats\\\\default.png')
                    Storage::disk('public')->delete($file);
                }
                $i++;
            }
            if($id !== null) {
                $this->photos = json_encode($files);
                $this->save();
            }
            if(count($files) === 0) {
                $this->photos = '["flats\\\\default.png"]';
                $this->save();
            }
        }
    }

    public function lastOrder() {
        $order = FlatServiceOrder::where('flat_id', $this->id)->where('status', 'Выполнен')->orderBy('id', 'desc')->first();
        if(!$order){
            $order = FlatServiceOrder::where('flat_id', $this->id)->where('status', 'Утверждён')->orderBy('id', 'desc')->first();
        }
        if(!$order){
            $order = FlatServiceOrder::where('flat_id', $this->id)->where('status', 'Принят')->orderBy('id', 'desc')->first();
        }
        if(!$order){
            $order = new FlatServiceOrder();
        }
        return $order;
    }
}
