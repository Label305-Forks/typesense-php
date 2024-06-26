<?php

namespace Feature;

use Tests\ConversationsTestCase;


class ConversationModelTest extends ConversationsTestCase
{
    private $id = '123';

    public function testCanGetAModelById(): void
    {
        $this->mockApiCall()->allows()->get($this->endPointPath(), [])->andReturns([]);

        $response = $this->mockConversations()->models[$this->id]->retrieve();
        $this->assertEquals([], $response);
    }

    public function testCanUpdateAModelById(): void
    {
        $data = [
            "system_prompt" => "You are an assistant for question-answering..."
        ];
        $this->mockApiCall()->allows()->put($this->endPointPath(), $data)->andReturns([]);

        $response = $this->mockConversations()->models[$this->id]->update($data);
        $this->assertEquals([], $response);
    }

    public function testCanDeleteAModelById(): void
    {
        $this->mockApiCall()->allows()->delete($this->endPointPath())->andReturns([]);

        $response = $this->mockConversations()->models[$this->id]->delete();
        $this->assertEquals([], $response);
    }

    private function endPointPath(): string
    {
        return sprintf('%s/%s', '/conversations/models', $this->id);
    }
}
