-- Canonical URL form (no trailing slash); the warm-up and verify scripts use
-- the same form, so no stack answers the load with a redirect.
local categories = {}
for i = 1, 100 do
  categories[i] = string.format("/articles/category-%03d", i)
end

request = function()
  local path = categories[math.random(#categories)]
  return wrk.format("GET", path)
end
