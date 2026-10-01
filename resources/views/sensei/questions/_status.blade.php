@switch($status)
    @case('published')<span class="badge ok">Siap pakai</span>@break
    @case('draft')<span class="badge warn">Draft</span>@break
    @default<span class="badge">Diarsipkan</span>
@endswitch
