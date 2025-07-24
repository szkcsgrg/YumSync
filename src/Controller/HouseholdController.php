<?php

namespace App\Controller;

use App\Entity\Household;
use App\Entity\HouseholdUsers;
use App\Entity\User;
use App\Entity\CreatedShops;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/household')]
#[IsGranted('ROLE_USER')]
class HouseholdController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/create', name: 'household_create', methods: ['POST'])]
    public function createHousehold(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? '';

        if (empty($name)) {
            return new JsonResponse(['success' => false, 'message' => 'Household name is required'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Check if user is already in a household
        $existingMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier()]);

        if ($existingMembership) {
            return new JsonResponse(['success' => false, 'message' => 'You are already in a household'], 400);
        }

        // Generate unique 6-digit ID
        do {
            $householdId = random_int(100000, 999999);
            $existing = $this->entityManager->getRepository(Household::class)
                ->findOneBy(['householdID' => $householdId]);
        } while ($existing);

        // Create household
        $household = new Household();
        $household->setHouseholdID($householdId);
        $household->setName($name);
        $household->setOwnerUserId($user->getUserIdentifier());

        $this->entityManager->persist($household);

        // Add user as owner
        $householdUser = new HouseholdUsers();
        $householdUser->setUserId($user->getUserIdentifier());
        $householdUser->setHouseholdId($householdId);
        $householdUser->setRole('owner');
        $householdUser->setStatus('approved');
        $householdUser->setJoinedAt((new \DateTime())->format('Y-m-d H:i:s'));

        $this->entityManager->persist($householdUser);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Household created successfully',
            'household' => [
                'id' => $household->getHouseholdID(),
                'name' => $household->getName(),
                'role' => 'owner'
            ]
        ]);
    }

    #[Route('/join-request', name: 'household_join_request', methods: ['POST'])]
    public function requestJoinHousehold(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $householdId = $data['householdId'] ?? '';

        if (empty($householdId)) {
            return new JsonResponse(['success' => false, 'message' => 'Household ID is required'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Check if user is already in a household (approved status only)
        $existingMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'status' => 'approved']);

        if ($existingMembership) {
            return new JsonResponse(['success' => false, 'message' => 'You are already in a household'], 400);
        }

        // Check if user already has a pending request for this household
        $pendingRequest = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'householdId' => (int)$householdId, 'status' => 'pending']);

        if ($pendingRequest) {
            return new JsonResponse(['success' => false, 'message' => 'You already have a pending request for this household'], 400);
        }

        // Find household
        $household = $this->entityManager->getRepository(Household::class)
            ->findOneBy(['householdID' => (int)$householdId]);
        if (!$household) {
            return new JsonResponse(['success' => false, 'message' => 'Household not found'], 404);
        }

        // Create join request
        $householdUser = new HouseholdUsers();
        $householdUser->setUserId($user->getUserIdentifier());
        $householdUser->setHouseholdId((int)$householdId);
        $householdUser->setRole('member');
        $householdUser->setStatus('pending');
        $householdUser->setJoinedAt((new \DateTime())->format('Y-m-d H:i:s'));

        $this->entityManager->persist($householdUser);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Join request sent successfully',
            'household' => [
                'name' => $household->getName()
            ]
        ]);
    }

    #[Route('/pending-requests', name: 'household_pending_requests', methods: ['GET'])]
    public function getPendingRequests(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Get user's household where they are owner
        $ownerMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'role' => 'owner', 'status' => 'approved']);

        if (!$ownerMembership) {
            return new JsonResponse(['success' => false, 'message' => 'Not an owner of any household'], 403);
        }

        // Get pending requests for this household
        $pendingRequests = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findBy(['householdId' => $ownerMembership->getHouseholdId(), 'status' => 'pending']);

        $requests = [];
        foreach ($pendingRequests as $request) {
            $userEntity = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $request->getUserId()]);
            if ($userEntity) {
                $requests[] = [
                    'id' => $request->getId(),
                    'username' => $userEntity->getName(),
                    'requestedAt' => $request->getJoinedAt()
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'pendingRequests' => $requests
        ]);
    }

    #[Route('/approve-request', name: 'household_approve_request', methods: ['POST'])]
    public function approveJoinRequest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $requestId = $data['requestId'] ?? 0;

        /** @var User $user */
        $user = $this->getUser();

        // Get the join request
        $joinRequest = $this->entityManager->getRepository(HouseholdUsers::class)->find($requestId);
        if (!$joinRequest || $joinRequest->getStatus() !== 'pending') {
            return new JsonResponse(['success' => false, 'message' => 'Request not found or already processed'], 404);
        }

        // Check if current user is owner of the household
        $ownerMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy([
                'userId' => $user->getUserIdentifier(),
                'householdId' => $joinRequest->getHouseholdId(),
                'role' => 'owner',
                'status' => 'approved'
            ]);

        if (!$ownerMembership) {
            return new JsonResponse(['success' => false, 'message' => 'Not authorized to approve requests'], 403);
        }

        // Approve the request
        $joinRequest->setStatus('approved');
        
        // Also mark the user's initial setup as complete now that they're approved
        $approvedUser = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $joinRequest->getUserId()]);
        
        if ($approvedUser) {
            $approvedUser->setInitialSetupDone(true);
        }
        
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Join request approved successfully'
        ]);
    }

    #[Route('/reject-request', name: 'household_reject_request', methods: ['POST'])]
    public function rejectJoinRequest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $requestId = $data['requestId'] ?? 0;

        /** @var User $user */
        $user = $this->getUser();

        // Get the join request
        $joinRequest = $this->entityManager->getRepository(HouseholdUsers::class)->find($requestId);
        if (!$joinRequest || $joinRequest->getStatus() !== 'pending') {
            return new JsonResponse(['success' => false, 'message' => 'Request not found or already processed'], 404);
        }

        // Check if current user is owner of the household
        $ownerMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy([
                'userId' => $user->getUserIdentifier(),
                'householdId' => $joinRequest->getHouseholdId(),
                'role' => 'owner',
                'status' => 'approved'
            ]);

        if (!$ownerMembership) {
            return new JsonResponse(['success' => false, 'message' => 'Not authorized to reject requests'], 403);
        }

        // Remove the request
        $this->entityManager->remove($joinRequest);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Join request rejected successfully'
        ]);
    }

    #[Route('/my-household', name: 'household_my_household', methods: ['GET'])]
    public function getMyHousehold(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $membership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'status' => 'approved']);

        if (!$membership) {
            return new JsonResponse(['success' => false, 'message' => 'Not in any household']);
        }

        $household = $this->entityManager->getRepository(Household::class)
            ->findOneBy(['householdID' => $membership->getHouseholdId()]);

        if (!$household) {
            return new JsonResponse(['success' => false, 'message' => 'Household not found']);
        }

        return new JsonResponse([
            'success' => true,
            'household' => [
                'id' => $household->getHouseholdID(),
                'name' => $household->getName(),
                'role' => $membership->getRole()
            ]
        ]);
    }

    #[Route('/members', name: 'household_members', methods: ['GET'])]
    public function getHouseholdMembers(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Get user's household where they are owner
        $ownerMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'role' => 'owner']);

        if (!$ownerMembership) {
            return new JsonResponse(['success' => false, 'message' => 'Not an owner of any household'], 403);
        }

        // Get all members of this household
        $members = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findBy(['householdId' => $ownerMembership->getHouseholdId(), 'status' => 'approved']);

        $memberData = [];
        foreach ($members as $member) {
            $userEntity = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $member->getUserId()]);
            if ($userEntity) {
                $memberData[] = [
                    'userId' => $member->getUserId(),
                    'username' => $userEntity->getName(),
                    'role' => $member->getRole(),
                    'joinedAt' => $member->getJoinedAt()
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'members' => $memberData
        ]);
    }

    #[Route('/remove-member', name: 'household_remove_member', methods: ['POST'])]
    public function removeMember(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $userId = $data['userId'] ?? '';

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Get current user's household where they are owner
        $ownerMembership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $currentUser->getUserIdentifier(), 'role' => 'owner']);

        if (!$ownerMembership) {
            return new JsonResponse(['success' => false, 'message' => 'Not an owner of any household'], 403);
        }

        // Find the member to remove
        $memberToRemove = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $userId]);
        if (!$memberToRemove) {
            return new JsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }

        // Find their membership in this household
        $membershipToRemove = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy([
                'userId' => $userId,
                'householdId' => $ownerMembership->getHouseholdId(),
                'status' => 'approved'
            ]);

        if (!$membershipToRemove) {
            return new JsonResponse(['success' => false, 'message' => 'User is not a member of this household'], 404);
        }

        // Prevent owner from removing themselves
        if ($membershipToRemove->getRole() === 'owner') {
            return new JsonResponse(['success' => false, 'message' => 'Cannot remove the household owner'], 400);
        }

        // Remove user's shops before removing them from household
        $this->removeUserShops($memberToRemove);

        // Remove the membership
        $this->entityManager->remove($membershipToRemove);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Member removed successfully'
        ]);
    }

    #[Route('/leave', name: 'household_leave', methods: ['POST'])]
    public function leaveHousehold(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Find user's household membership
        $membership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier(), 'status' => 'approved']);

        if (!$membership) {
            return new JsonResponse(['success' => false, 'message' => 'Not in any household'], 404);
        }

        // Prevent owner from leaving (they need to transfer ownership or delete household)
        if ($membership->getRole() === 'owner') {
            return new JsonResponse(['success' => false, 'message' => 'Household owner cannot leave. Please transfer ownership or delete the household.'], 400);
        }

        // Remove user's shops before leaving household
        $this->removeUserShops($user);

        // Remove the membership
        $this->entityManager->remove($membership);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'You have left the household successfully'
        ]);
    }

    #[Route('/debug-household', name: 'household_debug', methods: ['GET'])]
    public function debugHousehold(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Get user's household membership
        $membership = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findOneBy(['userId' => $user->getUserIdentifier()]);
            
        if (!$membership) {
            return new JsonResponse(['message' => 'User not in any household', 'userId' => $user->getId()]);
        }
        
        // Get all household members
        $allMembers = $this->entityManager->getRepository(HouseholdUsers::class)
            ->findBy(['householdId' => $membership->getHouseholdId()]);
            
        $memberData = [];
        foreach ($allMembers as $member) {
            $userEntity = $this->entityManager->getRepository(User::class)->find($member->getUserId());
            $memberData[] = [
                'userId' => $member->getUserId(),
                'username' => $userEntity ? $userEntity->getName() : 'Unknown',
                'role' => $member->getRole(),
                'joinedAt' => $member->getJoinedAt()
            ];
        }
        
        return new JsonResponse([
            'currentUser' => $user->getId(),
            'householdId' => $membership->getHouseholdId(),
            'userRole' => $membership->getRole(),
            'allMembers' => $memberData
        ]);
    }

    private function removeUserShops(User $user): void
    {
        // Remove all shops created by this user
        $userShops = $this->entityManager->getRepository(CreatedShops::class)
            ->findBy(['userId' => $user->getUserIdentifier()]);

        foreach ($userShops as $shop) {
            $this->entityManager->remove($shop);
        }
    }
}
