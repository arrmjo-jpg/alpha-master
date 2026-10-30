import { describe, expect, it } from 'vitest';
import { readCookie } from './cookies';
import { ApiError, toApiError } from './problem';

describe('toApiError', () => {
  it('reads RFC 9457 problem details from the API', () => {
    const error = toApiError(422, {
      type: 'https://alphamaster.dev/problems/validation-error',
      title: 'The given data was invalid.',
      status: 422,
      code: 'VALIDATION_ERROR',
      errors: { identifier: [{ code: 'required', message: 'The identifier field is required.' }] },
      trace_id: 'req-1',
    });
    expect(error).toBeInstanceOf(ApiError);
    expect(error.code).toBe('VALIDATION_ERROR');
    expect(error.message).toBe('The given data was invalid.');
    expect(error.fieldErrors).toEqual({ identifier: 'The identifier field is required.' });
    expect(error.traceId).toBe('req-1');
  });

  it('prefers detail over title', () => {
    expect(toApiError(403, { title: 'Forbidden', detail: 'Protected role.' }).message).toBe('Protected role.');
  });

  it('keeps the status when the body is not problem details', () => {
    const error = toApiError(502, '<html>Bad gateway</html>');
    expect(error.status).toBe(502);
    expect(error.code).toBe('HTTP_ERROR');
    expect(error.fieldErrors).toEqual({});
  });

  it('parses Retry-After for rate limits', () => {
    expect(toApiError(429, { code: 'RATE_LIMIT_EXCEEDED' }, '42').retryAfter).toBe(42);
    expect(toApiError(429, null, 'soon').retryAfter).toBeUndefined();
  });

  it('marks network failures with status 0', () => {
    expect(new ApiError(0, null).code).toBe('NETWORK_ERROR');
  });
});

describe('readCookie', () => {
  it('decodes the Laravel XSRF-TOKEN cookie', () => {
    const jar = 'theme=dark; XSRF-TOKEN=eyJpdiI6IkFiQz0ifQ%3D%3D; alphamaster_session=abc';
    expect(readCookie('XSRF-TOKEN', jar)).toBe('eyJpdiI6IkFiQz0ifQ==');
  });

  it('returns null when the cookie is missing', () => {
    expect(readCookie('XSRF-TOKEN', 'a=1; b=2')).toBeNull();
  });
});
