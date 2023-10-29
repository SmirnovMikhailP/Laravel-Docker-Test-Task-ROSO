<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class EventController extends Controller
{
    public function store(Request $request)
    {
//  1. Принимает только поля title(varchar название мероприятия), place(varchar место проведения мероприятия) и date(date дата проведения мероприятия)
        $validatedData = $request->validate([
            'title' => 'required|string',
            'place' => 'required|string',
            'date' => 'required|date',
        ]);
//  2. Создает сущность "Событие" через модель Event
        $event = new Event();
        $event->title = $validatedData['title'];
        $event->place = $validatedData['place'];
        $event->date = $validatedData['date'];

        $event->period = $this->calculatePeriod($event->date);
        $event->period_type = $this->calculatePeriodType($event->date);

        $event->save();
/*4. Созданную и сохраненную сущность отправить в 5ти минутный кеш и в очередь
Обработчик очереди делать не нужно. БД создавать и подключать тоже. Работоспособность кода не важна, главное - способ реализации.*/
        Cache::put('event', $event, now()->addMinutes(5));

        Queue::push(function () use ($event) {
            // Обработчик очереди
        });

        return response()->json([
            'message' => 'Event created successfully',
            'event' => $event
        ], 201);
    }
/*5. Запросом из роута нужно выгрузить список сущностей и также задействовать кеш (если есть и не просрочился).
Выгружается полями name (конкатенация title + place), дата проведения в формате (d.m.Y) и период в формате "через 13 день, было 2 год назад" (склонять не надо)*/
    public function index()
    {
        $event = Cache::get('event');

        if ($event) {
            $name = $event->title . ' ' . $event->place;
            $date = Carbon::parse($event->date)->format('d.m.Y');
            $period = $this->formatPeriod($event->period, $event->period_type);

            return response()->json([
                'name' => $name,
                'date' => $date,
                'period' => $period
            ]);
        }

        return response()->json([
            'message' => 'Event not found'
        ], 404);
    }
/*3. Поля period(signed int период от/до события) и period_type(char день/месяц/год) заполняются в зависимости от пришеднего в контроллер date(date дата проведения мероприятия).
Если дата проведения мероприятия в прошлом (мероприятие прошло) тогда period >= 0, в противном случае period < 0 (событие в будущем).
Если до даты события меньше месяца, то это дни. Если меньше года, то месяцы.
Пример, событие было 2 года, 3 месяца, 2 дня назад - в таком случае period = 2, period_type = год. Или событие через 13 дней - period = -13, period_type = день*/
    private function calculatePeriod($date)
    {
        $eventDate = Carbon::parse($date);

        if ($eventDate->isPast()) {
            return $eventDate->diffInDays();
        }

        return -$eventDate->diffInDays();
    }

    private function calculatePeriodType($date)
    {
        $eventDate = Carbon::parse($date);

        if ($eventDate->isPast()) {
            if ($eventDate->diffInDays() < 30) {
                return 'день';
            } elseif ($eventDate->diffInDays() < 365) {
                return 'месяц';
            } else {
                return 'год';
            }
        }

        if ($eventDate->diffInDays() < 30) {
            return 'день';
        } elseif ($eventDate->diffInDays() < 365) {
            return 'месяц';
        } else {
            return 'год';
        }
    }

    private function formatPeriod($period, $periodType)
    {
        if ($period > 0) {
            return "через $period $periodType";
        } elseif ($period < 0) {
            return "было " . abs($period) . " $periodType назад";
        } else {
            return '';
        }
    }
}
