<?php

/*
| Dua domain, satu aplikasi & satu database.
| - LMS  (mis. jlpt.jgg-jlpt.com)      : siswa LPK, sensei, staf.
| - Tes  (mis. testonline.jgg-jlpt.com): peserta tes umum/berbayar saja.
| Bila PORTAL_TEST_HOST kosong, pemisahan dimatikan dan semua peran masuk lewat domain mana pun.
*/
return [
    'lms_host' => env('PORTAL_LMS_HOST'),
    'test_host' => env('PORTAL_TEST_HOST'),
];
