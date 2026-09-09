<?php

namespace App\Enums;

enum GamePhase: string
{
    case Idle = 'idle';
    case Buzzing = 'buzzing';
    case Buzzed = 'buzzed';
    case Answering = 'answering';
    case Result = 'result';
}
