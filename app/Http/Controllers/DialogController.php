<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Dialog;
use App\User;
use Illuminate\Support\Facades\Auth;
use App\Message;
use App\Http\Requests\MessageRequest;
use App\FlatServiceOrder;
use App\HouseholdServiceOrder;
use Illuminate\Support\Facades\DB;

class DialogController extends Controller
{
    public function index(Request $request) {
        $viewName = $request->route()->getName() == "admin-dialog-list" ? 'dialog.admin-list' : 'dialog.user-list';
        $dialogs = Dialog::join('messages', 'messages.dialog_id', '=', 'dialogs.id')
            ->select('dialogs.*', DB::raw('MAX(messages.created_at) as messages_max_date'), DB::raw('COUNT(messages.id) as messages_count'));
        if($viewName === 'dialog.admin-list') {
            $dialogs = $dialogs->where('dialogs.type', 'Поддержка');
        } else {
            $dialogs = $dialogs->where("dialogs.first_user_id", Auth::id())->orWhere("dialogs.second_user_id", Auth::id());
        }
        $dialogs = $dialogs
            ->groupBy('dialogs.id')
            ->having('messages_count', '>', 0)
            ->orderBy('messages_max_date', 'desc')
            ->paginate(20);
        return view($viewName, ['dialogs' => $dialogs, 'route' => $request->route()->getName() == "admin-dialog-list" ? 'admin-dialog-show' : 'dialog-show' ]);
    }

    public function show(Request $request, $id) {
        $viewName = $request->route()->getName() == "admin-dialog-show" ? 'dialog.admin-show' : 'dialog.user-show';
        $dialog = Dialog::find($id);
        if($dialog != null) {
            if(!($dialog->first_user_id == Auth::id() || $dialog->second_user_id == Auth::id() || Auth::user()->role->name == 'admin')) {
                return redirect()->route('index');
            }
            $count = $dialog->readMessages();
            return view($viewName, ['dialog' => $dialog, 'count' => $count]);
        } else {
            return redirect()->route('index');
        }
    }

    public function create(Request $request, $id) {
        $routeName = $request->route()->getName() == "admin-dialog-create" ? 'admin-dialog-show' : 'dialog-show';
        $user = User::find($id);
        if($user != null) {
            $authId = Auth::id(); $id = $user->id;
            $type = Auth::user()->role->name == 'admin' ? 'Поддержка' : 'Обычный';
            $dialog = Dialog::whereRaw("first_user_id in ($authId, $id)")->whereRaw("second_user_id in ($authId, $id)")->where('type', $type)->first();
            if(!$dialog) {
                $dialog = Dialog::create(['first_user_id' => $authId, 'second_user_id' => $id, 'type' => $type]);
                $dialog->save();
            }
            return redirect()->route($routeName, ['id' => $dialog->id]);
        } else {
            return redirect()->route('index');
        }
    }

    public function createFlat(Request $request, $id) {
        $flatService = FlatServiceOrder::find($id);
        $authId = Auth::id();
        if(Auth::user()->role->name === 'tenant') {
            $id = $flatService->flat->user_id;
        } else {
            $id = $flatService->tenant_id;
        }
        if(Auth::user()->role->name == 'landlord') {
            $flatService->read_status = 'Прочитано';
            $flatService->save();
        }
        $dialog = Dialog::where("flat_order_id", $flatService->id)->first();
        if(!$dialog) {
            $dialog = Dialog::create(['first_user_id' => $authId, 'second_user_id' => $id, 'type' => 'Квартира', 'flat_order_id' => $flatService->id]);
            $dialog->save();
        }
        return redirect()->route('dialog-show', ['id' => $dialog->id]);
    }

    public function createService(Request $request, $id) {
        $serviceOrder = HouseholdServiceOrder::find($id);
        $authId = Auth::id();
        if(Auth::user()->role->name === 'landlord') {
            $id = $serviceOrder->household_service->user_id;
        } else {
            $id = $serviceOrder->landlord_id;
        }
        if(Auth::user()->role->name == 'employee') {
            $serviceOrder->read_status = 'Прочитано';
            $serviceOrder->save();
        }
        $dialog = Dialog::where("household_service_order_id", $serviceOrder->id)->first();
        if(!$dialog) {
            $dialog = Dialog::create(['first_user_id' => $authId, 'second_user_id' => $id, 'type' => 'Работа', 'household_service_order_id' =>$serviceOrder->id]);
            $dialog->save();
        }
        return redirect()->route('dialog-show', ['id' => $dialog->id]);
    }

    public function support(Request $request) {
        $id = Auth::id();
        $dialog = Dialog::create(['first_user_id' => $id, 'second_user_id' => $id, 'type' => 'Поддержка']);
        $dialog->save();
        return redirect()->route('dialog-show', ['id' => $dialog->id]);
    }

    public function createMessage(MessageRequest $request, $id) {
        $routeName = $request->route()->getName() == "admin-send-message" ? 'admin-dialog-show' : 'dialog-show';
        $dialog = Dialog::find($id);
        if(!$dialog) {
            return redirect()->route('index');
        } else if( !($dialog->first_user_id == Auth::id() || $dialog->second_user_id == Auth::id() || Auth::user()->role->name == 'admin')) {
            return redirect()->route('index');
        }
        if($request->file) {
            $type = 'Файл';
            $message = 'QWERTY';
        } else {
            $type = 'Текст';
            $message = $request->message;
        }
        $message = Message::create([
            'message' => $message,
            'type' => $type,
            'user_id' => Auth::id(),
            'dialog_id' => $id
        ]);
        if($request->file) {
            $message->message = $message->upload($request);
        }
        $message->save();
        return redirect()->route($routeName, ['id' => $message->dialog->id]);
    }

    public function removeMessage(Request $request, $id) {
        $routeName = $request->route()->getName() == "admin-remove-message" ? 'admin-dialog-show' : 'dialog-show';
        $message = Message::find($id);
        if($message != null) {
            if(Auth::user()->role->name === 'admin' || $message->user->id === Auth::id()) {
                $message->deleteFile();
                $message->delete();
                return redirect()->route($routeName, ['id' => $message->dialog->id]);
            } else {
                return redirect()->route('index');
            }
        } else {
            return redirect()->route('index');
        }
    }

    public function getLastMessages($id) {
        if(count(explode('i', $id)) == 2) {
            Dialog::find(explode('i', $id)[1])->readMessages();
            return Message::where('dialog_id', explode('i', $id)[1] )->get()->toArray();
        } else {
            $message = Message::find($id);
            if ($message != null) {
                $message->dialog->readMessages();
                $messages = Message::where('dialog_id', $message->dialog_id)->where('id', '>', $message->id)->get();
                return $messages->toArray();
            } else {
                return redirect()->route('index');
            }
        }
    }


}
