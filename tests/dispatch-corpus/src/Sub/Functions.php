<?php

namespace App\Sub;

function aliasTarget(): void
{
    \App\Gateway::staticExpensive();
}
