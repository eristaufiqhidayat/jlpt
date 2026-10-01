<?php

return [
    // Folder public/pdf milik aplikasi lama. File di sana berekstensi .html tetapi isinya PDF.
    'pdf_path' => env('LEGACY_PDF_PATH'),

    // Folder audio choukai lama (nama file ada di jwb_soal.flag1). Dicari juga di subfolder.
    'audio_path' => env('LEGACY_AUDIO_PATH'),

    // Disk tujuan salinan file (private, tidak bisa diakses langsung dari browser).
    'disk' => env('LEGACY_DISK', 'local'),
    'directory' => 'legacy',
];
