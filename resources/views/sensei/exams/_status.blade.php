@switch($exam->status->value)
    @case('open')<span class="badge ok">Dibuka</span>@break
    @case('closed')<span class="badge">Ditutup</span>@break
    @default<span class="badge warn">Draft</span>
@endswitch
