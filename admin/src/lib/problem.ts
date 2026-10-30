import { z } from 'zod';

/** RFC 9457 Problem Details as the API renders them (ADR-0006, ProblemRenderer). */
export const problemSchema = z.looseObject({
  type: z.string().optional(),
  title: z.string().optional(),
  status: z.number().optional(),
  code: z.string().optional(),
  detail: z.string().optional(),
  errors: z.record(z.string(), z.array(z.looseObject({ code: z.string(), message: z.string() }))).optional(),
  trace_id: z.string().optional(),
});

export type Problem = z.infer<typeof problemSchema>;

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  /** First server message per field, for 422 responses. */
  readonly fieldErrors: Record<string, string>;
  readonly traceId?: string;
  readonly retryAfter?: number;

  constructor(status: number, problem: Problem | null, retryAfter?: number) {
    super(problem?.detail ?? problem?.title ?? `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.code = problem?.code ?? (status === 0 ? 'NETWORK_ERROR' : 'HTTP_ERROR');
    this.traceId = problem?.trace_id;
    this.retryAfter = retryAfter;
    this.fieldErrors = Object.fromEntries(
      Object.entries(problem?.errors ?? {}).flatMap(([field, list]) => (list[0] ? [[field, list[0].message]] : [])),
    );
  }
}

/** Parses an error body; anything that is not Problem Details becomes a bare status error. */
export function toApiError(status: number, body: unknown, retryAfterHeader?: string | null): ApiError {
  const parsed = problemSchema.safeParse(body);
  const retryAfter = retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : undefined;
  return new ApiError(status, parsed.success ? parsed.data : null, Number.isFinite(retryAfter) ? retryAfter : undefined);
}
