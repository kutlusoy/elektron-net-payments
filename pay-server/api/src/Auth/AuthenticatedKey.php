<?php

namespace ElektronNet\Payments\PayServer\Auth;

final class AuthenticatedKey
{
    public string $merchantId;
    /** @var string[] */
    public array $scopes;

    /**
     * @param string[] $scopes
     */
    public function __construct(string $merchantId, array $scopes)
    {
        $this->merchantId = $merchantId;
        $this->scopes = $scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
