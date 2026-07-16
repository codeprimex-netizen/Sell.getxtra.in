// k6 load test for Sell.getxtra.in (Req 16.5 / 24.3).
//
// Validates the product listing/search SLO: server-side P95 <= 400ms under
// target load. Run:
//   k6 run -e BASE_URL=https://staging.sell.getxtra.in deploy/perf/k6-load-test.js
//
// CI gates on the thresholds below (k6 exits non-zero if breached).

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

const listingLatency = new Trend('listing_latency', true);
const searchLatency = new Trend('search_latency', true);

export const options = {
  scenarios: {
    browse: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 50 },   // ramp
        { duration: '3m', target: 200 },  // sustained target load
        { duration: '1m', target: 0 },    // ramp down
      ],
    },
  },
  thresholds: {
    // SLO: product listing & search P95 <= 400ms server-side.
    'listing_latency': ['p(95)<400'],
    'search_latency': ['p(95)<400'],
    'http_req_failed': ['rate<0.01'],   // < 1% errors
    'http_req_duration{endpoint:listing}': ['p(95)<400'],
    'http_req_duration{endpoint:search}': ['p(95)<400'],
  },
};

export default function () {
  const listing = http.get(`${BASE}/api/v1/products?per_page=24`, { tags: { endpoint: 'listing' } });
  check(listing, { 'listing 200': (r) => r.status === 200 });
  listingLatency.add(listing.timings.duration);

  const search = http.get(`${BASE}/search?q=theme`, { tags: { endpoint: 'search' } });
  check(search, { 'search ok': (r) => r.status === 200 || r.status === 302 });
  searchLatency.add(search.timings.duration);

  sleep(1);
}
