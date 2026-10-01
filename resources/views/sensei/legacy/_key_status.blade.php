@switch($status->value)
    @case('ok')<span class="badge ok">{{ $status->label() }}</span>@break
    @case('incomplete')<span class="badge warn">{{ $status->label() }}</span>@break
    @case('invalid_keys')<span class="badge err">{{ $status->label() }}</span>@break
    @default<span class="badge info">{{ $status->label() }}</span>
@endswitch
