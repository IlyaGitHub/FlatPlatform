<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['required', 'string', 'regex:/^(\s*)?(\+)?([- _():=+]?\d[- _():=+]?){10,14}(\s*)?$/'],
            'reserve_phone' => ['nullable', 'regex:/^(\s*)?(\+)?([- _():=+]?\d[- _():=+]?){10,14}(\s*)?$/'],
//            'passport_number' => ['string', 'regex:/^[0-9]{9}$/'],
//            'date_of_birth' => ['date', 'before:today'],
//            'date_of_issue' => ['date', 'before:today'],
            'type' => ['required', 'in:Арендатор,Арендодатель,Работник'],
//            'photo' => ['string'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\User
     */
    protected function create(array $data)
    {
        $data['phone'] = str_replace([' ', '-', '(', ')', '_', ':', '='], '', $data['phone']);
        return User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'last_name' => $data['last_name'],
            'type' => $data['type'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'reserve_phone' => $data['reserve_phone'] ?? null,
//            'passport_number' => $data['passport_number'] ?? null,
//            'date_of_birth' => $data['date_of_birth'] ?? null,
//            'date_of_issue' => $data['date_of_issue'] ?? null,
//            'photo' => $data['photo'] ?? null,
        ]);
    }
}
