## Guest workload (wrk2, constant offered rate)

| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 30 | 29.96 | 373.50ms | 626.69ms | 0 | 1.8 MiB | 4.0 MiB | 78.0 MiB |
| evo-parser | tracing | 30 | 30.06 | 327.68ms | 573.44ms | 0 | 1.8 MiB | 4.0 MiB | 86.9 MiB |
| evo-latte | off | 30 | 30.01 | 380.16ms | 688.64ms | 0 | 1.8 MiB | 4.0 MiB | 81.9 MiB |
| evo-latte | tracing | 30 | 30.07 | 331.77ms | 559.61ms | 0 | 1.9 MiB | 4.0 MiB | 89.5 MiB |
| evo-latte-parser | off | 30 | 29.96 | 370.43ms | 625.15ms | 0 | 1.8 MiB | 4.0 MiB | 81.3 MiB |
| evo-latte-parser | tracing | 30 | 30.07 | 322.82ms | 587.26ms | 0 | 1.9 MiB | 4.0 MiB | 89.9 MiB |
| evo-phalcon | off | 30 | 30.89 | 48.19ms | 98.62ms | 0 | 1.0 MiB | 2.0 MiB | 71.1 MiB |
| evo-phalcon | tracing | 30 | 29.82 | 45.44ms | 105.41ms | 0 | 1.0 MiB | 2.0 MiB | 79.2 MiB |
| drupal-11 | off | 30 | 30.75 | 76.80ms | 137.34ms | 0 | 2.1 MiB | 4.0 MiB | 70.2 MiB |
| drupal-11 | tracing | 30 | 30.85 | 74.11ms | 163.20ms | 0 | 2.1 MiB | 4.0 MiB | 88.4 MiB |
| typo3 | off | 30 | 30.88 | 45.85ms | 82.30ms | 0 | 3.1 MiB | 4.0 MiB | 81.5 MiB |
| typo3 | tracing | 30 | 30.88 | 45.69ms | 74.11ms | 0 | 3.1 MiB | 4.0 MiB | 178.2 MiB |

## Admin workload (Playwright; medians per action, wall / server)

| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | 1801.45 / 231.22 ms | 164.97 / 97.02 ms | 742.83 / 418.82 ms | 150.34 / 84.15 ms | 855.68 / 501.98 ms | 171.98 / 120.64 ms | 11528 ms |
| evo-parser | tracing | 1843.25 / 289.29 ms | 203.36 / 131.78 ms | 868.98 / 544.45 ms | 163.14 / 110.5 ms | 957.57 / 625.22 ms | 285.19 / 243.68 ms | 13261 ms |
| evo-latte | off | 1796.47 / 236.22 ms | 167.32 / 96.98 ms | 765.55 / 424.76 ms | 140.41 / 83.26 ms | 867.98 / 529.1 ms | 221.56 / 175.61 ms | 11963 ms |
| evo-latte | tracing | 1842.31 / 276.43 ms | 209.94 / 137.18 ms | 878.58 / 541.34 ms | 170.51 / 111.35 ms | 953.84 / 627.61 ms | 269.11 / 226.12 ms | 13154 ms |
| evo-latte-parser | off | 1853.42 / 246.8 ms | 159.19 / 99 ms | 748 / 413.92 ms | 138.18 / 87.62 ms | 829.9 / 483.38 ms | 224.78 / 177.56 ms | 11878 ms |
| evo-latte-parser | tracing | 1821.65 / 271.61 ms | 208.76 / 134.19 ms | 863.98 / 537.28 ms | 166.75 / 108.63 ms | 933.58 / 596.27 ms | 210.25 / 160.3 ms | 12892 ms |
| evo-phalcon | off | 1806.83 / 246.35 ms | 163.94 / 104.18 ms | 754.28 / 424.28 ms | 142.53 / 83.66 ms | 852.59 / 505.07 ms | 175.33 / 119.83 ms | 11712 ms |
| evo-phalcon | tracing | 1829.27 / 299.92 ms | 184.85 / 128.04 ms | 901.17 / 558.27 ms | 168.52 / 111.92 ms | 975.54 / 651.23 ms | 279.95 / 240.1 ms | 13437 ms |
| drupal-11 | off | 518.95 / 410.19 ms | 255.78 / 202.62 ms | 756.37 / 696.64 ms | 91.75 / 44.16 ms | 733.56 / 677.63 ms | 288.67 / 167.95 ms | 9804 ms |
| drupal-11 | tracing | 386.45 / 276.98 ms | 150.76 / 104.48 ms | 356.49 / 294.94 ms | 92.25 / 44.09 ms | 359.91 / 288.75 ms | 262.23 / 108.65 ms | 5411 ms |
| typo3 | off | 1662.1 / 1241.17 ms | 482.85 / 145.47 ms | 721.04 / 368.34 ms | 275.92 / 98.89 ms | 1730.76 / 749.64 ms | 183.32 / 110.4 ms | 17971 ms |
| typo3 | tracing | 1483.14 / 1000.97 ms | 495.56 / 154.29 ms | 781.4 / 360.74 ms | 392.34 / 305.72 ms | 1864.73 / 974.53 ms | 175.52 / 109.05 ms | 19072 ms |

## Admin workload memory (medians per action)

| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | FPM container peak |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| evo-parser | off | PHP peak (script) | 2.5 MiB | 1.23 MiB | 5.94 MiB | 1.26 MiB | 5.93 MiB | 2.12 MiB | 83.8 MiB |
| evo-parser | off | Frontend JS heap | 14.03 MiB | 11.54 MiB | 12.86 MiB | 8.99 MiB | 9.02 MiB | 10.2 MiB |  |
| evo-parser | off | Frontend DOM nodes | 27289 | 8294 | 9742 | 3600 | 3732 | 3872 |  |
| evo-parser | tracing | PHP peak (script) | 2.56 MiB | 1.42 MiB | 11.44 MiB | 1.45 MiB | 11.43 MiB | 2.25 MiB | 95.6 MiB |
| evo-parser | tracing | Frontend JS heap | 14.01 MiB | 10.07 MiB | 9.42 MiB | 9.24 MiB | 9.04 MiB | 8.82 MiB |  |
| evo-parser | tracing | Frontend DOM nodes | 27228 | 5472 | 4094 | 3713 | 3732 | 2557 |  |
| evo-latte | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 95.9 MiB |
| evo-latte | off | Frontend JS heap | 14.02 MiB | 11.57 MiB | 12.79 MiB | 8.98 MiB | 9.4 MiB | 8.83 MiB |  |
| evo-latte | off | Frontend DOM nodes | 27356 | 8294 | 9742 | 3600 | 3671 | 2557 |  |
| evo-latte | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 90 MiB |
| evo-latte | tracing | Frontend JS heap | 14.86 MiB | 10.07 MiB | 9.83 MiB | 9.28 MiB | 9.03 MiB | 9.44 MiB |  |
| evo-latte | tracing | Frontend DOM nodes | 27309 | 5466 | 5414 | 3594 | 3732 | 3539 |  |
| evo-latte-parser | off | PHP peak (script) | 2.53 MiB | 1.24 MiB | 5.95 MiB | 1.27 MiB | 5.94 MiB | 2.13 MiB | 99.5 MiB |
| evo-latte-parser | off | Frontend JS heap | 14 MiB | 16.15 MiB | 17.08 MiB | 10.2 MiB | 10.21 MiB | 10.58 MiB |  |
| evo-latte-parser | off | Frontend DOM nodes | 27371 | 21118 | 19542 | 5415 | 4877 | 5017 |  |
| evo-latte-parser | tracing | PHP peak (script) | 2.59 MiB | 1.61 MiB | 11.45 MiB | 1.42 MiB | 11.44 MiB | 2.25 MiB | 92.5 MiB |
| evo-latte-parser | tracing | Frontend JS heap | 14.83 MiB | 11.13 MiB | 12.11 MiB | 10.2 MiB | 9.38 MiB | 9.24 MiB |  |
| evo-latte-parser | tracing | Frontend DOM nodes | 27309 | 7482 | 8930 | 4848 | 3671 | 2557 |  |
| evo-phalcon | off | PHP peak (script) | 2.59 MiB | 1.24 MiB | 6.02 MiB | 1.28 MiB | 6.01 MiB | 2.14 MiB | 90.8 MiB |
| evo-phalcon | off | Frontend JS heap | 13.99 MiB | 9.8 MiB | 9.38 MiB | 9.26 MiB | 10.54 MiB | 13.14 MiB |  |
| evo-phalcon | off | Frontend DOM nodes | 27309 | 5472 | 4094 | 4429 | 5497 | 8097 |  |
| evo-phalcon | tracing | PHP peak (script) | 2.6 MiB | 1.39 MiB | 11.52 MiB | 1.42 MiB | 11.51 MiB | 2.26 MiB | 98.6 MiB |
| evo-phalcon | tracing | Frontend JS heap | 14.02 MiB | 9.82 MiB | 9.85 MiB | 10.54 MiB | 9.17 MiB | 9.23 MiB |  |
| evo-phalcon | tracing | Frontend DOM nodes | 27309 | 5411 | 5414 | 6350 | 3610 | 2557 |  |
| drupal-11 | off | PHP peak (script) | 3.11 MiB | 4.82 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 97 MiB |
| drupal-11 | off | Frontend JS heap | 11.91 MiB | 20.13 MiB | 20.91 MiB | 34.99 MiB | 36.09 MiB | 44.23 MiB |  |
| drupal-11 | off | Frontend DOM nodes | 2504 | 4808 | 5027 | 8865 | 9084 | 11102 |  |
| drupal-11 | tracing | PHP peak (script) | 3.11 MiB | 4.89 MiB | 4.23 MiB | 4.69 MiB | 4.23 MiB | 2.83 MiB | 79.8 MiB |
| drupal-11 | tracing | Frontend JS heap | 11.87 MiB | 19.72 MiB | 20.88 MiB | 34.92 MiB | 36.02 MiB | 43.85 MiB |  |
| drupal-11 | tracing | Frontend DOM nodes | 2501 | 4805 | 5021 | 8862 | 9078 | 11099 |  |
| typo3 | off | PHP peak (script) | 6.27 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 33.5 MiB |
| typo3 | off | Frontend JS heap | 34.13 MiB | 43.14 MiB | 36.38 MiB | 38.76 MiB | 36.52 MiB | 40.86 MiB |  |
| typo3 | off | Frontend DOM nodes | 34136 | 38430 | 35259 | 24280 | 30889 | 19910 |  |
| typo3 | tracing | PHP peak (script) | 6.33 MiB | 7.28 MiB | 7.34 MiB | 6.53 MiB | 6.93 MiB | 4.5 MiB | 32.9 MiB |
| typo3 | tracing | Frontend JS heap | 31.93 MiB | 71 MiB | 71.28 MiB | 121.17 MiB | 130.7 MiB | 18.07 MiB |  |
| typo3 | tracing | Frontend DOM nodes | 32859 | 70225 | 78869 | 153005 | 147777 | 12401 |  |
