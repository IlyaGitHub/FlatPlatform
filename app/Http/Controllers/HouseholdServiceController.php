<?php

namespace App\Http\Controllers;

use App\HouseholdService;
use App\HouseholdServiceCategory;
use Illuminate\Http\Request;
use App\Http\Requests\DateRequest;
use Illuminate\Support\Facades\Auth;
use App\Flat;
use App\FlatServiceOrder;
use App\Http\Requests\DateIssueRequest;
use App\HouseholdServiceOrder;
use App\Dialog;
use App\Http\Requests\HouseholdServiceRequest;
use App\Message;

class HouseholdServiceController extends Controller
{
    public function index($id)
    {
        $householdService = HouseholdService::find($id);
        if ($householdService != null){
            return view('household-service.index', ['householdService'=>$householdService]);
        } else{
            return redirect()->route('index');
        }
    }

    public function search(Request $request)
    {
        $householdServices = HouseholdService::filtrateHouseholdService($request, 20);
        return view('household-service.search', ['householdServices' => $householdServices, 'categories' => HouseholdServiceCategory::all(), 'request' => $request]);
    }

    public function addRequest(HouseholdServiceRequest $request, $id) {
        $user = Auth::user(); $service = HouseholdService::find($id);
        if(!$service) {
            return redirect()->route('index');
        } elseif($user->role->name !== 'landlord') {
            $request->session()->flash('status-error', 'Вы не арендатор!');
        } else {
            HouseholdServiceOrder::create([
                'price' =>$request->price,
                'employee_confirmation' => 0,
                'landlord_confirmation' => 0,
                'date_of_completion' => $request->date_of_completion,
                'status' => 'Создан',
                'landlord_id' => $user->id,
                'household_service_id' => $id,
                'flat_id' => $request->flat_id
            ]);
            $request->session()->flash('status-success', 'Заявка на роботу добавлена!');
        }
        return redirect()->route('household-service-page', ['id' => $id]);
    }

    public function rejectRequest(Request $request, $id) {
        if(Auth::user()->role->name === 'landlord') {
            return $this->patchStatus($request, $id, 'Отозван', 'Вы не владелец заявки!', 'Заявка на роботу отозвана!', 'landlord');
        } else {
            return $this->patchStatus($request, $id, 'Отменён', 'Вы не владелец объявления!', 'Заявка на роботу отклонена!', 'employee');
        }
    }

    public function acceptRequest(Request $request, $id) {
        return $this->patchStatus(
            $request,
            $id,
            'Принят',
            'Вы не владелец объявления!',
            'Заявка на роботу принята!',
            'employee',
            function ($serviceOrder, $request, $messageError) {
                $serviceOrder->read_status = 'Прочитано';
                $serviceOrder->save();
                $dialog = Dialog::where("household_service_order_id", $serviceOrder->id)->first();
                if(!$dialog) {
                    $dialog = Dialog::create(['first_user_id' => $serviceOrder->employee->id, 'second_user_id' => $serviceOrder->landlord->id, 'type' => 'Работа', 'household_service_order_id' => $serviceOrder->id]);
                }
                Message::createStatusMessage($serviceOrder->status, $serviceOrder->dialogs[0]->id, false);
                $dialog->save();
//                return redirect()->route('dialog-show', ['id' => $dialog->id]);
                return redirect()->back();
            }
        );
    }

    public function confirmRequest(Request $request, $id) {
        return $this->patchStatus(
            $request,
            $id,
            'Принят',
            'Вы не участник сделки!',
            'Условия приняты!',
            Auth::user()->role->name,
            function ($serviceOrder, $request, $messageError) {
                if(Auth::user()->role->name === 'employee') {
                    $serviceOrder->employee_confirmation = 1;
                } else {
                    $serviceOrder->landlord_confirmation = 1;
                }
                if($serviceOrder->employee_confirmation && $serviceOrder->landlord_confirmation) {
                    $serviceOrder->status = 'Утверждён';
                }
                $serviceOrder->save();
                Message::createStatusMessage($serviceOrder->status, $serviceOrder->dialogs[0]->id, false);
//                return redirect()->route('dialog-show', ['id' => $serviceOrder->dialogs->first()->id]);
                return redirect()->back();
            }
        );
    }

    public function completeRequest(Request $request, $id) {
        return $this->patchStatus(
            $request,
            $id,
            'Выполнен',
            'Вы не роботник!',
            'Сделка выполнена!',
            'employee',
            function ($serviceOrder, $request, $messageError) {
                if($serviceOrder->employee_confirmation && $serviceOrder->landlord_confirmation) {
                    $serviceOrder->status = 'Выполнен';
                    $serviceOrder->save();
                    Message::createStatusMessage($serviceOrder->status, $serviceOrder->dialogs[0]->id, false);
                } else {
                    $request->session()->forget('status-success');
                    $request->session()->flash('status-error', $messageError);
                }
//                return redirect()->route('dialog-show', ['id' => $serviceOrder->dialogs->first()->id]);
                return redirect()->back();
            }
        );
    }

    public function updateRequest(HouseholdServiceRequest $request, $id) {
        $user = Auth::user(); $serviceOrder = HouseholdServiceOrder::find($id);
        if(!$serviceOrder || $user->role->name !== 'employee') {
            $request->session()->flash('status-error', 'Вы не имеете доступа к данному действию!');
            return redirect()->route('index');
        } else {
            if($serviceOrder->price !== $request->price || $serviceOrder->date_of_completion !== $request->date_of_completion) {
                if($serviceOrder->price !== $request->price) {
                    Message::createPriceMessage($user->role->display_name, $request->price, $serviceOrder->dialogs[0]->id);
                }
                $serviceOrder->update($request->all());
                $serviceOrder->employee_confirmation = 0;
                $serviceOrder->landlord_confirmation = 0;
                $serviceOrder->save();
                $request->session()->flash('status-success', 'Заявка на роботу обновлена!');
            }
            return redirect()->route('dialog-show', ['id' => $serviceOrder->dialogs[0]->id]);
        }
    }

    private function patchStatus(Request $request, $id, $status, $messageError, $messageSuccess, $userType, $callback = null) {
        $user = Auth::user(); $serviceOrder = HouseholdServiceOrder::find($id); $isChangeStatus = false;
        if(!$serviceOrder) {
            return redirect()->route('index');
        } elseif($user->id !== $serviceOrder->$userType->id) {
            $request->session()->flash('status-error', $messageError);
        } else {
            if($status == $serviceOrder->status && ($status == 'Отменён' || $status == 'Отозван')) {
                $isChangeStatus = true;
                $serviceOrder->status = 'Создан';
            } else {
                $serviceOrder->status = $status;
            }
            if($status == 'Отменён'){
                $serviceOrder->read_status = 'Прочитано';
            }
            $serviceOrder->save();
            $request->session()->flash('status-success', $messageSuccess);
        }
        if(is_callable($callback)) {
            return $callback($serviceOrder, $request, $messageError);
        } else {
            Message::createStatusMessage($serviceOrder->status, $serviceOrder->dialogs[0]->id, false, $isChangeStatus);
//            return redirect()->route('household-service-page', ['id' => $serviceOrder->household_service->id]);
            return redirect()->back();
        }
    }

}
