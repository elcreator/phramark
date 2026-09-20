| Runtime | Wall (s, best of 5) | Pages/s | Peak RSS (MiB) | vs native |
| --- | ---: | ---: | ---: | ---: |
| native | 2.93 | 6826 | 171.0 | 1.00× |
| php-jit-off | 1.54 | 12987 | 27.2 | 0.53× |
| php-jit-tracing | 1.42 | 14085 | 28.9 | 0.48× |

20000 pages per run; manticore 0.10.0, Debian clang version 19.1.7 (3+b1), PHP 8.5.10; compile 0.4s, binary 1396408 bytes.
