<?php

declare(strict_types=1);

use OpenTelemetry\Sdk\Trace\PropagationMap;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Sdk\Trace\TraceState;
use PHPUnit\Framework\TestCase;
use Propagators\Aws\Xray\AwsXrayPropagator;

class AwsXrayPropagatorTest extends TestCase
{
    private const VERSION_NUMBER = '1';
    private const TRACE_ID = '5759e988bd862e3fe1be46a994272793';
    private const TRACE_ID_TIMESTAMP = '5759e988';
    private const TRACE_ID_RANDOMHEX = 'bd862e3fe1be46a994272793';
    private const SPAN_ID = '53995c3f42cd8ad8';
    private const IS_SAMPLED = '1';
    private const NOT_SAMPLED = '0';
    private const TRACE_ID_HEADER_SAMPLED = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';
    private const TRACE_ID_HEADER_NOT_SAMPLED = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=0';

    /**
     * @test
     * fields() should return an array of fields with AWS X-Ray Trace ID Header
     */
    public function TestFields()
    {
        $propagator = new AwsXrayPropagator();
        $this->assertSame(AwsXrayPropagator::fields(), [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER]);
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is sampled
     * restore(string $traceId, string $spanId, bool $sampled = false, bool $isRemote = false, ?API\TraceState $traceState = null): SpanContext
     */
    public function InjectValidSampledTraceId()
    {
        $carrier = [];
        $map = new PropagationMap();
        $context = SpanContext::restore(self::TRACE_ID, self::SPAN_ID, true, false);
        AwsXrayPropagator::inject($context, $carrier, $map);

        $this->assertSame(self::TRACE_ID_HEADER_SAMPLED, $map->get($carrier, AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER));
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is not sampled
     */
    public function InjectValidNotSampledTraceId()
    {
        $carrier = [];
        $map = new PropagationMap();
        $context = SpanContext::restore(self::TRACE_ID, self::SPAN_ID, false, false);
        AwsXrayPropagator::inject($context, $carrier, $map);

        $this->assertSame(self::TRACE_ID_HEADER_NOT_SAMPLED, $map->get($carrier, AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER));
    }

    /**
     * @test
     * Test inject with tracestate
     */
    public function InjectTraceIdWithTraceState()
    {
        $carrier = [];
        $map = new PropagationMap();
        $tracestate = new TraceState('vendor1=opaqueValue1');
        $context = SpanContext::restore(self::TRACE_ID, self::SPAN_ID, true, false, $tracestate);
        AwsXrayPropagator::inject($context, $carrier, $map);

        $this->assertSame(self::TRACE_ID_HEADER_SAMPLED, $map->get($carrier, AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER));
    }

    /**
     * @test
     * Test with an invalid spanContext, should return null
     */
    public function InjectTraceIdWithInvalidSpanContext()
    {
        $carrier = [];
        $map = new PropagationMap();
        $context = SpanContext::restore(SpanContext::INVALID_TRACE, SpanContext::INVALID_SPAN, true, false);
        AwsXrayPropagator::inject($context, $carrier, $map);

        $this->assertNull($map->get($carrier, AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER));
    }

    /**
     * @test
     * Test sampled, not sampled, extra fields, arbitrary order
     */
    public function ExtractValidSampledContext()
    {
        $traceHeaders = ['Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=0',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Foo=Bar;Parent=53995c3f42cd8ad8;Sampled=0', ];

        foreach ($traceHeaders as $traceHeader) {
            $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
            $map = new PropagationMap();
            $context = AwsXrayPropagator::extract($carrier, $map);

            $this->assertSame(self::TRACE_ID, $context->getTraceId());
            $this->assertSame(self::SPAN_ID, $context->getSpanId());
            $this->assertSame(substr($traceHeader, -1), ($context->isSampled() ? '1' : '0'));
            $this->assertTrue($context->isRemote());
        }
    }

    /**
     * @test
     * Test arbitrary order
     */
    public function ExtractValidSampledContextAbitraryOrder()
    {
        $traceHeader = 'Parent=53995c3f42cd8ad8;Sampled=1;Root=1-5759e988-bd862e3fe1be46a994272793';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(self::TRACE_ID, $context->getTraceId());
        $this->assertSame(self::SPAN_ID, $context->getSpanId());
        $this->assertSame(self::IS_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertTrue($context->isRemote());
    }

    /**
     * @test
     * Must have '-' and not other delimiters
     */
    public function ExtractInvalidTraceIdDelimiter()
    {
        $traceHeader = 'Root=1*5759e988*bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Should return invalid spanContext
     */
    public function ExtractEmptySpanContext()
    {
        $traceHeader = '';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test different invalidSpanContexts
     */
    public function ExtractInvalidSpanContext()
    {
        $traceHeaders = [' ', 'abc-def-hig', '123abc'];

        foreach ($traceHeaders as $traceHeader) {
            $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
            $map = new PropagationMap();
            $context = AwsXrayPropagator::extract($carrier, $map);

            $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
            $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
            $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
            $this->assertFalse($context->isRemote());
        }
    }

    /**
     * @test
     * Test Invalid Trace Id
     */
    public function ExtractInvalidTraceId()
    {
        $traceHeader = 'Root=1-00000000-000000000000000000000000;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Trace Id Length
     */
    public function ExtractInvalidTraceIdLength()
    {
        $traceHeader = 'Root=1-5759e98s46v8-bd862e3fe1frbe46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Span Id
     */
    public function ExtractInvalidSpanId()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=0000000000000000;Sampled=1';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Span Id
     */
    public function ExtractInvalidSpanIdLength()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad85dg;Sampled=1';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test null sample value
     */
    public function ExtractNullSampledValue()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test invalid sample value
     */
    public function ExtractInvalidSampleValue()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=12345';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test incorrect xray version
     */
    public function ExtractInvalidXrayVersion()
    {
        $traceHeader = 'Root=2-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=12345';

        $carrier = [AwsXrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $map = new PropagationMap();
        $context = AwsXrayPropagator::extract($carrier, $map);

        $this->assertSame(SpanContext::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContext::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }
}
