<?php

// Same Latte file view as evo-latte, but with the EVO pass on: after Latte
// renders, the core parser runs its tag passes over the output (the aLatteX
// default). evo-latte measures the view without it; the difference between
// the two stacks is the cost of that pass.
return ['evo_tags' => true];
