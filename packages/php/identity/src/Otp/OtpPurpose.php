<?php

namespace Starter\Identity\Otp;

enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case Invitation = 'invitation';
    case Confirm = 'confirm';
}
